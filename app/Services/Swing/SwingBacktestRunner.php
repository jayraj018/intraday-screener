<?php

namespace App\Services\Swing;

use App\Services\CandleStore;
use Illuminate\Support\Carbon;

/**
 * Replays the swing strategies over history and produces trades.
 *
 * This is what was missing: SwingBacktestService could walk a setup forward and
 * WalkForwardService could split time honestly, but nothing ever fed a strategy into
 * either. Every swing number the system could show was therefore an assumption.
 *
 * Two properties matter more than speed here:
 *
 * 1. Nothing sees the future. Each bar is judged on a slice ending at that bar, and the
 *    walk starts after it. The benchmark is sliced by DATE to the same bar, not by index,
 *    because a stock that missed a session would otherwise be compared against a
 *    benchmark window offset from its own.
 *
 * 2. One position per stock at a time. Seven strategies firing on one stock across
 *    consecutive days would otherwise record seven overlapping trades on the same move,
 *    inflating the trade count and making correlated outcomes look independent.
 */
class SwingBacktestRunner
{
    public function __construct(
        protected CandleStore $candles,
        protected MarketRegimeService $regimes,
        protected SwingContextBuilder $contexts,
        protected SwingBacktestService $walker,
        protected SwingScannerService $scanner,
        protected DataQualityService $dataQuality,
    ) {
    }

    /**
     * @param  array<int, string>  $symbols
     * @param  ?callable  $onTrades  fn(array<int, SwingTrade> $trades, string $symbol): void
     * @param  ?callable  $afterEachSymbol  fn(string $symbol): void
     * @param  ?array  $window  ['from' => date, 'to' => date] bounding the days a signal
     *                          may be TAKEN on; history before `from` is still read
     * @return array{symbols: int, skipped: int, trades: int}
     */
    public function run(
        array $symbols,
        ?callable $onTrades = null,
        ?callable $afterEachSymbol = null,
        ?array $window = null,
        ?array $strategies = null,
    ): array {
        $strategies ??= $this->scanner->strategies();
        $benchmark = $this->candles->remember(config('swing.regime.benchmark'), '1d', '5y');
        $benchmarkByDate = $this->indexByDate($benchmark);

        $totals = ['symbols' => 0, 'skipped' => 0, 'trades' => 0];

        foreach ($symbols as $symbol) {
            $daily = $this->candles->remember($symbol, '1d', '5y');
            $weekly = $this->candles->remember($symbol, '1wk', '5y');

            if (! $this->dataQuality->assess($daily, $benchmark, $weekly)['sufficient']) {
                $totals['skipped']++;
                $afterEachSymbol && $afterEachSymbol($symbol);

                continue;
            }

            $trades = $this->replay($symbol, $daily, $weekly, $benchmark, $benchmarkByDate, $strategies, $window);

            $totals['symbols']++;
            $totals['trades'] += count($trades);

            if ($trades && $onTrades) {
                $onTrades($trades, $symbol);
            }

            $afterEachSymbol && $afterEachSymbol($symbol);
        }

        return $totals;
    }

    /**
     * @return array<int, SwingTrade>
     */
    protected function replay(string $symbol, array $daily, array $weekly, array $benchmark, array $benchmarkByDate, array $strategies, ?array $window): array
    {
        $trades = [];
        $warmup = config('swing.data_quality.min_daily_bars');
        $weeklyByDate = $this->indexByDate($weekly);

        // The bar a position, once opened, runs until. Nothing new is taken before it.
        $busyUntil = -1;

        for ($i = $warmup; $i < count($daily) - 1; $i++) {
            $date = $daily[$i]['date'];

            if ($window && ($date < $window['from'] || $date > $window['to'])) {
                continue;
            }

            if ($i <= $busyUntil) {
                continue;
            }

            // Sliced by date, not by index: a stock with a missing session would
            // otherwise read a benchmark window shifted from its own.
            $benchmarkSlice = $this->upTo($benchmark, $benchmarkByDate, $date);
            $weeklySlice = $this->upTo($weekly, $weeklyByDate, $date);

            if (count($benchmarkSlice) < config('swing.data_quality.min_benchmark_bars')) {
                continue;
            }

            $context = $this->contexts->build(
                $symbol,
                array_slice($daily, 0, $i + 1),
                $weeklySlice,
                $benchmarkSlice,
                $this->regimes->detect($benchmarkSlice),
            );

            if (! $context) {
                continue;
            }

            foreach ($strategies as $strategy) {
                $signal = $strategy->evaluate($context);

                if (! $signal->isActionable()) {
                    continue;
                }

                $trade = $this->walker->walk($signal->setup, $daily, $i);

                if (! $trade) {
                    continue;
                }

                $trades[] = $trade;

                // Hold the stock until this position closes. The first strategy to fire
                // takes the trade; the rest are the same move seen twice.
                $busyUntil = $i + $trade->barsHeld;

                break;
            }
        }

        return $trades;
    }

    /** date => index, so a slice can be taken by date in constant time. */
    protected function indexByDate(array $candles): array
    {
        $index = [];

        foreach ($candles as $i => $candle) {
            $index[$candle['date']] = $i;
        }

        return $index;
    }

    /**
     * Everything up to and including $date. Falls back to a scan when that exact date is
     * absent from this series — a holiday for one exchange, a halt for one stock.
     */
    protected function upTo(array $candles, array $index, string $date): array
    {
        if (isset($index[$date])) {
            return array_slice($candles, 0, $index[$date] + 1);
        }

        $count = 0;

        foreach ($candles as $candle) {
            if ($candle['date'] > $date) {
                break;
            }

            $count++;
        }

        return array_slice($candles, 0, $count);
    }

    /**
     * The market regime on a given date, for splitting results by the conditions they
     * were earned in. Read from the benchmark up to that date only.
     */
    public function regimeOn(string $date): array
    {
        $benchmark = $this->candles->remember(config('swing.regime.benchmark'), '1d', '5y');

        return $this->regimes->detect($this->upTo($benchmark, $this->indexByDate($benchmark), $date));
    }

    /** Trading days available, used to size a backtest window honestly. */
    public function coverage(): array
    {
        $benchmark = $this->candles->remember(config('swing.regime.benchmark'), '1d', '5y');

        return [
            'bars' => count($benchmark),
            'from' => $benchmark ? $benchmark[0]['date'] : null,
            'to' => $benchmark ? end($benchmark)['date'] : null,
        ];
    }
}
