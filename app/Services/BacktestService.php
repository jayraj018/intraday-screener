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
        protected ScreenerService $screener,
        protected TradeCostService $costs
    ) {
    }

    /**
     * Replay every testable strategy on past data, handing each completed trade to
     * $onTrades a symbol at a time.
     *
     * Trades are streamed rather than returned in one array: a Nifty 500 run produces
     * roughly a hundred thousand of them, which will not fit in the 256 MB the
     * production container allows PHP.
     *
     * Positive Earnings can't be replayed because old news isn't available, so it never
     * appears in the results.
     */
    public function run(array $symbols, callable $onTrades, ?callable $afterEachSymbol = null): void
    {
        foreach ($symbols as $symbol) {
            $trades = [
                ...$this->replayDailyStrategies($symbol),
                ...$this->replayOrb($symbol),
            ];

            if ($trades) {
                $onTrades($trades);
            }

            if ($afterEachSymbol) {
                $afterEachSymbol($symbol);
            }
        }
    }

    /**
     * A signal found at day D's close is traded on day D+1, like the 9:05 AM scan.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function replayDailyStrategies(string $symbol): array
    {
        $candles = $this->dataService->getDailyCandles($symbol, config('screener.backtest_range'));

        if (! $candles) {
            return [];
        }

        // Today's candle is still forming, so it can't be used as a trading day's result.
        if (end($candles)['date'] === now('Asia/Kolkata')->toDateString()) {
            array_pop($candles);
        }

        $trades = [];

        for ($d = self::DAILY_WINDOW - 1; $d < count($candles) - 1; $d++) {
            $window = array_slice($candles, $d - self::DAILY_WINDOW + 1, self::DAILY_WINDOW);
            $ctx = $this->screener->dailyContext($window);

            if (! $ctx) {
                continue;
            }

            foreach ($this->screener->dailySetups($symbol, $window, $ctx) as $setup) {
                $trade = $this->dailyTrade($symbol, $setup, $candles[$d], $candles[$d + 1]);

                if ($trade) {
                    $trades[] = $trade;
                }
            }
        }

        return $trades;
    }

    /**
     * Take the setup on the following session and see how it finished.
     *
     * The old version compared the signal day's closing price against the next day's
     * high and low — an entry at a price that had already gone, which quietly handed
     * every trade the overnight gap. The entry is now the next session's open, which is
     * the first price the signal could actually have been acted on.
     */
    protected function dailyTrade(string $symbol, array $setup, array $signalDay, array $tradingDay): ?array
    {
        $isBuy = str_contains($setup['signal'], 'BUY');

        // The stop distance the strategy chose, re-applied to the price actually paid:
        // risk is measured from where the position was opened, not from the signal.
        $riskPerShare = abs($setup['entry'] - $setup['stop_loss']);

        if ($riskPerShare <= 0) {
            return null;
        }

        $reference = config('screener.execution.entry') === 'signal_close'
            ? $setup['entry']
            : $tradingDay['open'];

        $entry = $this->costs->slip($reference, $isBuy);
        $stop = $isBuy ? $entry - $riskPerShare : $entry + $riskPerShare;
        $target = $isBuy
            ? $entry + $riskPerShare * config('screener.risk_reward_ratio')
            : $entry - $riskPerShare * config('screener.risk_reward_ratio');

        $quantity = $this->costs->quantity($entry, $riskPerShare);

        if ($quantity < 1) {
            return null; // the stop is wider than the whole risk budget
        }

        // A daily candle doesn't reveal which level was touched first, so a day that
        // reached both counts as the stop. Being wrong in the pessimistic direction is
        // the only safe way to be wrong here.
        if ($isBuy ? $tradingDay['low'] <= $stop : $tradingDay['high'] >= $stop) {
            [$exit, $reason] = [$stop, 'stop'];
        } elseif ($isBuy ? $tradingDay['high'] >= $target : $tradingDay['low'] <= $target) {
            [$exit, $reason] = [$target, 'target'];
        } else {
            [$exit, $reason] = [$this->costs->slip($tradingDay['close'], ! $isBuy), 'session_close'];
        }

        return $this->buildTrade(
            $symbol, $setup['strategy'], $isBuy,
            $signalDay['date'], $setup['entry'],
            $tradingDay['date'], $entry,
            $tradingDay['date'], $exit, $reason,
            $quantity, $riskPerShare, 1
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function replayOrb(string $symbol): array
    {
        $candles = $this->dataService->getIntradayCandles($symbol, '60d');

        if (! $candles) {
            return [];
        }

        $days = [];
        foreach ($candles as $candle) {
            $days[gmdate('Y-m-d', $candle['timestamp'] + 19800)][] = $candle; // IST date
        }

        unset($days[now('Asia/Kolkata')->toDateString()]); // today's session isn't over yet

        $trades = [];

        foreach ($days as $date => $dayCandles) {
            // The detector judges each candle against only the candles before it, so
            // handing it the whole session returns the same breakout the live scan finds
            // — it no longer has to be fed one candle at a time to stay honest.
            $setup = $this->screener->calculateOrbSetup($dayCandles, $vwap = $this->indicators->vwap($dayCandles));

            if (! $setup) {
                continue;
            }

            // Entry is the open after the confirming candle, exactly as it is live
            $entryIndex = $setup['breakout_index'] + 1;
            $filled = $this->screener->fillOrbSetup($setup, $dayCandles[$entryIndex] ?? null);

            if (! $filled) {
                continue; // confirmed on the session's last candle — nothing left to enter on
            }

            $trade = $this->orbTrade(
                $symbol, $date, $filled,
                array_slice($dayCandles, $entryIndex),
                array_slice($vwap, $entryIndex)
            );

            if ($trade) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Walk the session from the entry candle onwards until a level is hit, the trailing
     * stop closes it, or the session runs out.
     */
    protected function orbTrade(string $symbol, string $date, array $filled, array $rest, array $restVwap): ?array
    {
        if (! $rest) {
            return null;
        }

        $isBuy = $filled['type'] === 'BUY (Long)';
        $riskPerShare = abs($filled['entry'] - $filled['stop_loss']);

        if ($riskPerShare <= 0) {
            return null;
        }

        $entry = $this->costs->slip($filled['entry'], $isBuy);
        $quantity = $this->costs->quantity($entry, $riskPerShare);

        if ($quantity < 1) {
            return null;
        }

        $exit = null;
        $reason = null;
        $bars = 0;

        foreach ($rest as $i => $candle) {
            $bars = $i + 1;

            if ($isBuy ? $candle['low'] <= $filled['stop_loss'] : $candle['high'] >= $filled['stop_loss']) {
                [$exit, $reason] = [$filled['stop_loss'], 'stop'];
                break;
            }

            if ($isBuy ? $candle['high'] >= $filled['target'] : $candle['low'] <= $filled['target']) {
                [$exit, $reason] = [$filled['target'], 'target'];
                break;
            }

            // The strategy trails its stop at VWAP: a close back through VWAP exits.
            if (isset($restVwap[$i]) && ($isBuy ? $candle['close'] <= $restVwap[$i] : $candle['close'] >= $restVwap[$i])) {
                [$exit, $reason] = [$this->costs->slip($candle['close'], ! $isBuy), 'vwap_trail'];
                break;
            }
        }

        if ($exit === null) {
            // Nothing closed it, so it is squared off at the session's last price
            [$exit, $reason] = [$this->costs->slip(end($rest)['close'], ! $isBuy), 'session_close'];
        }

        return $this->buildTrade(
            $symbol, 'ORB + VWAP Breakout', $isBuy,
            $date, $filled['confirm_price'],
            $date, $entry,
            $date, $exit, $reason,
            $quantity, $riskPerShare, $bars
        );
    }

    /**
     * Assemble one trade, charging it what the round trip actually costs.
     */
    protected function buildTrade(
        string $symbol,
        string $strategy,
        bool $isBuy,
        string $signalDate,
        float $signalPrice,
        string $entryDate,
        float $entry,
        string $exitDate,
        float $exit,
        string $reason,
        int $quantity,
        float $riskPerShare,
        int $bars
    ): array {
        $grossPnl = ($isBuy ? $exit - $entry : $entry - $exit) * $quantity;
        $costs = $this->costs->roundTripCosts($entry, $exit, $quantity, $isBuy);
        $netPnl = $grossPnl - $costs;

        return [
            'symbol' => $symbol,
            'strategy' => $strategy,
            'direction' => $isBuy ? 'BUY' : 'SELL',
            'signal_date' => $signalDate,
            'signal_price' => round($signalPrice, 4),
            'entry_date' => $entryDate,
            'entry_price' => round($entry, 4),
            'exit_date' => $exitDate,
            'exit_price' => round($exit, 4),
            'exit_reason' => $reason,
            'quantity' => $quantity,
            'gross_pnl' => round($grossPnl, 2),
            'costs' => $costs,
            'net_pnl' => round($netPnl, 2),
            'r_multiple' => round($netPnl / ($riskPerShare * $quantity), 4),
            'bars_held' => $bars,
        ];
    }
}
