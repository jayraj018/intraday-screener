<?php

namespace App\Services\Swing;

use App\Models\SwingSignal as SwingSignalRecord;
use App\Services\CandleStore;
use App\Services\ScreenerService;
use Illuminate\Support\Facades\DB;

/**
 * Runs the seven strategies across the watchlist and stores what they found.
 *
 * The market regime is computed once per scan, not per stock: it is a property of the
 * benchmark and identical for every symbol on a given day.
 *
 * NO_SETUP results are discarded — seven strategies across five hundred stocks would be
 * three and a half thousand rows a day of "nothing happened". Everything a trader might
 * act on or wait for is kept.
 */
class SwingScannerService
{
    public function __construct(
        protected CandleStore $candles,
        protected ScreenerService $screener,
        protected MarketRegimeService $regimes,
        protected SwingContextBuilder $contexts,
        protected SignalScoringService $scoring,
        protected HoldingPeriodService $holdingPeriods,
    ) {
    }

    /** @return array<int, SwingStrategyInterface> */
    public function strategies(): array
    {
        return array_map(fn ($class) => app($class), [
            Strategies\MaTrendStrategy::class,
            Strategies\TrendPullbackStrategy::class,
            Strategies\BreakoutVolumeStrategy::class,
            Strategies\BreakoutRetestStrategy::class,
            Strategies\MomentumContinuationStrategy::class,
            Strategies\RsiTrendReversalStrategy::class,
            Strategies\VolatilityContractionStrategy::class,
        ]);
    }

    /**
     * @param  ?callable  $afterEachSymbol  fn(string $symbol, int $kept): void
     * @return array{scanned: int, stored: int, states: array<string, int>}
     */
    public function scan(?array $symbols = null, ?callable $afterEachSymbol = null): array
    {
        $symbols ??= $this->screener->watchlist();
        $benchmark = $this->candles->remember(config('swing.regime.benchmark'), '1d', '2y');
        $regime = $this->regimes->detect($benchmark);
        $strategies = $this->strategies();
        $scanDate = now('Asia/Kolkata')->toDateString();

        // Estimated once per strategy rather than per signal: it is a property of the
        // strategy's recorded history, and re-querying it per row would be thousands of
        // identical aggregates.
        $holding = [];

        foreach ($strategies as $strategy) {
            $holding[$strategy->name()] = $this->holdingPeriods->estimate($strategy->name())['label'];
        }

        $rows = [];
        $states = [];
        $scanned = 0;

        foreach ($symbols as $symbol) {
            $kept = 0;
            $daily = $this->candles->remember($symbol, '1d', '2y');

            if (count($daily) >= 260) {
                $scanned++;
                $context = $this->contexts->build($symbol, $daily, $this->candles->remember($symbol, '1wk', '2y'), $benchmark, $regime);

                if ($context) {
                    foreach ($strategies as $strategy) {
                        $signal = $strategy->evaluate($context);
                        $states[$signal->state] = ($states[$signal->state] ?? 0) + 1;

                        if ($signal->state === SwingSignal::NO_SETUP) {
                            continue;
                        }

                        $rows[] = $this->row($scanDate, $context, $signal, $holding);
                        $kept++;
                    }
                }
            }

            if ($afterEachSymbol) {
                $afterEachSymbol($symbol, $kept);
            }
        }

        $this->store($scanDate, $rows);

        return ['scanned' => $scanned, 'stored' => count($rows), 'states' => $states];
    }

    protected function row(string $scanDate, SwingContext $context, SwingSignal $signal, array $holding): array
    {
        $setup = $signal->setup;
        $score = $this->scoring->score($context, $setup);

        return [
            'scan_date' => $scanDate,
            'symbol' => $context->symbol,
            'strategy' => $signal->strategy,
            'state' => $signal->state,
            'direction' => $setup?->direction,
            'score' => $score['score'],
            'signal_price' => round($context->close(), 4),
            'entry_trigger' => $setup?->entryTrigger,
            'stop_price' => $setup?->stop,
            'stop_method' => $setup?->stopMethod,
            'target1' => $setup?->target1,
            'target2' => $setup?->target2,

            // Derived from the plan, so a target can never carry the wrong R label
            'target1_r' => $setup?->rMultiple($setup->target1),
            'target2_r' => $setup?->target2 === null ? null : $setup->rMultiple($setup->target2),

            'holding_estimate' => $holding[$signal->strategy] ?? 'NOT ENOUGH DATA',
            'watch_for' => $signal->watchFor,
            'checks' => json_encode($signal->checks),
            'score_groups' => json_encode($score['groups']),
            'regime_trend' => $context->regime['trend'] ?? null,
            'regime_volatility' => $context->regime['volatility'] ?? null,
            'relative_strength' => json_encode($context->relativeStrength['periods']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Replace the day's rows in one transaction. A crash between the delete and the insert
     * previously left the intraday dashboard reading half a scan as if it were whole.
     */
    protected function store(string $scanDate, array $rows): void
    {
        DB::transaction(function () use ($scanDate, $rows) {
            SwingSignalRecord::where('scan_date', $scanDate)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                SwingSignalRecord::insert($chunk);
            }
        });
    }
}
