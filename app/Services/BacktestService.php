<?php

namespace App\Services;

class BacktestService
{
    /**
     * The live scan fetches ~3 months of daily candles, so each past day is judged on
     * the same size window — otherwise RSI/MACD values would differ from the live scan's.
     */
    protected const DAILY_WINDOW = 63;

    public function __construct(
        protected StockDataService $dataService,
        protected IndicatorService $indicators,
        protected ScreenerService $screener
    ) {
    }

    /**
     * Replay every testable strategy on past data and count same-day wins.
     *
     * A trade is closed on the day it is taken: at its target (win), its stop-loss
     * (loss), or the closing price (win only if in profit). Positive Earnings can't be
     * replayed because old news isn't available, so it never appears in the tally.
     *
     * @return array<string, array{trades: int, wins: int}>
     */
    public function run(array $symbols, ?callable $afterEachSymbol = null): array
    {
        $tally = [];

        foreach ($symbols as $symbol) {
            $this->replayDailyStrategies($symbol, $tally);
            $this->replayOrb($symbol, $tally);

            if ($afterEachSymbol) {
                $afterEachSymbol($symbol);
            }
        }

        return $tally;
    }

    /**
     * A signal found at day D's close is traded on day D+1, like the 9:05 AM scan.
     */
    protected function replayDailyStrategies(string $symbol, array &$tally): void
    {
        $candles = $this->dataService->getDailyCandles($symbol, config('screener.backtest_range'));

        if (! $candles) {
            return;
        }

        // Today's candle is still forming, so it can't be used as a trading day's result.
        if (end($candles)['date'] === now()->toDateString()) {
            array_pop($candles);
        }

        for ($d = self::DAILY_WINDOW - 1; $d < count($candles) - 1; $d++) {
            $window = array_slice($candles, $d - self::DAILY_WINDOW + 1, self::DAILY_WINDOW);
            $ctx = $this->screener->dailyContext($window);

            if (! $ctx) {
                continue;
            }

            foreach ($this->screener->dailySetups($symbol, $window, $ctx) as $setup) {
                $this->record($tally, $setup['strategy'], $this->dailyOutcome($setup, $candles[$d + 1]));
            }
        }
    }

    protected function dailyOutcome(array $setup, array $day): bool
    {
        $isBuy = str_contains($setup['signal'], 'BUY');

        // A daily candle doesn't show which level was touched first, so a day that
        // reached both the stop-loss and the target counts as a loss.
        if ($isBuy ? $day['low'] <= $setup['stop_loss'] : $day['high'] >= $setup['stop_loss']) {
            return false;
        }

        if ($isBuy ? $day['high'] >= $setup['target'] : $day['low'] <= $setup['target']) {
            return true;
        }

        return $isBuy ? $day['close'] > $setup['entry'] : $day['close'] < $setup['entry'];
    }

    protected function replayOrb(string $symbol, array &$tally): void
    {
        $candles = $this->dataService->getIntradayCandles($symbol, '60d');

        if (! $candles) {
            return;
        }

        $days = [];
        foreach ($candles as $candle) {
            $days[gmdate('Y-m-d', $candle['timestamp'] + 19800)][] = $candle; // IST date
        }

        unset($days[now('Asia/Kolkata')->toDateString()]); // today's session isn't over yet

        foreach ($days as $dayCandles) {
            $vwap = $this->indicators->vwap($dayCandles);

            // Grow the day one candle at a time so the setup only sees candles that
            // existed at that moment (it averages volume over the candles so far).
            for ($k = 4; $k <= count($dayCandles); $k++) {
                $setup = $this->screener->calculateOrbSetup(array_slice($dayCandles, 0, $k), array_slice($vwap, 0, $k));

                if ($setup) {
                    $this->record($tally, 'ORB + VWAP Breakout', $this->orbOutcome($setup, array_slice($dayCandles, $k), array_slice($vwap, $k)));
                    break;
                }
            }
        }
    }

    /**
     * Walk the rest of the session after the breakout. Null when the breakout came on
     * the day's last candle and there was no time left to trade it.
     */
    protected function orbOutcome(array $setup, array $rest, array $restVwap): ?bool
    {
        $isBuy = $setup['type'] === 'BUY (Long)';

        foreach ($rest as $i => $candle) {
            if ($isBuy ? $candle['low'] <= $setup['stop_loss'] : $candle['high'] >= $setup['stop_loss']) {
                return false;
            }

            if ($isBuy ? $candle['high'] >= $setup['target'] : $candle['low'] <= $setup['target']) {
                return true;
            }

            // The strategy trails its stop at VWAP: a close back through VWAP exits the trade.
            if ($isBuy ? $candle['close'] <= $restVwap[$i] : $candle['close'] >= $restVwap[$i]) {
                return $isBuy ? $candle['close'] > $setup['entry'] : $candle['close'] < $setup['entry'];
            }
        }

        if (! $rest) {
            return null;
        }

        $close = end($rest)['close'];

        return $isBuy ? $close > $setup['entry'] : $close < $setup['entry'];
    }

    protected function record(array &$tally, string $strategy, ?bool $win): void
    {
        if ($win === null) {
            return;
        }

        $tally[$strategy] ??= ['trades' => 0, 'wins' => 0];
        $tally[$strategy]['trades']++;
        $tally[$strategy]['wins'] += (int) $win;
    }
}
