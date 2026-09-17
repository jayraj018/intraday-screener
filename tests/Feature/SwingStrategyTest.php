<?php

namespace Tests\Feature;

use App\Services\Swing\MarketRegimeService;
use App\Services\Swing\Strategies\BreakoutVolumeStrategy;
use App\Services\Swing\Strategies\MaTrendStrategy;
use App\Services\Swing\Strategies\TrendPullbackStrategy;
use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingContextBuilder;
use App\Services\Swing\SwingSignal;
use Tests\TestCase;

class SwingStrategyTest extends TestCase
{
    /** @param array<int, array{c: float, h?: float, l?: float, v?: int}> $tail */
    protected function context(callable $priceAt, int $count = 320, array $tail = [], ?callable $benchmarkAt = null): SwingContext
    {
        $daily = [];

        for ($i = 0; $i < $count; $i++) {
            $price = $priceAt($i);
            $daily[] = [
                'date' => date('Y-m-d', strtotime("2024-01-01 +{$i} days")),
                'open' => $price, 'high' => $price + 2, 'low' => $price - 2, 'close' => $price,
                'volume' => 1_000_000,
            ];
        }

        // Replace the final bars, so a test can craft the exact setup it is about
        foreach ($tail as $offset => $bar) {
            $i = $count - count($tail) + $offset;
            $daily[$i] = [
                'date' => $daily[$i]['date'],
                'open' => $bar['o'] ?? $bar['c'],
                'high' => $bar['h'] ?? $bar['c'] + 1,
                'low' => $bar['l'] ?? $bar['c'] - 1,
                'close' => $bar['c'],
                'volume' => $bar['v'] ?? 1_000_000,
            ];
        }

        $benchmarkAt ??= fn ($i) => 100 + $i * 0.05;
        $benchmark = [];

        for ($i = 0; $i < $count; $i++) {
            $price = $benchmarkAt($i);
            $benchmark[] = [
                'date' => $daily[$i]['date'],
                'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price,
                'volume' => 1_000_000,
            ];
        }

        $regime = app(MarketRegimeService::class)->detect($benchmark);

        return app(SwingContextBuilder::class)->build('TESTCO', $daily, [], $benchmark, $regime);
    }


    /**
     * A series that rises, is rejected twice near 150, then does whatever the final bar
     * says. A monotonic ramp has no swing highs at all, so it can never produce a tested
     * resistance level — the peaks have to be real.
     *
     * @param  array{c: float, h?: float, l?: float, v?: int}  $finalBar
     */
    protected function resistanceContext(array $finalBar): SwingContext
    {
        $prices = [];

        for ($i = 0; $i < 260; $i++) {
            $prices[] = 100 + $i * 0.12 + sin($i / 5) * 1.5;
        }

        foreach ([144, 147, 150, 147, 144, 142] as $p) {   // first rejection at 150
            $prices[] = $p;
        }

        foreach ([140, 138, 140, 143, 146] as $p) {        // pullback
            $prices[] = $p;
        }

        foreach ([148, 150, 148, 145, 143] as $p) {        // second rejection at 150
            $prices[] = $p;
        }

        foreach ([145, 147, 148] as $p) {                  // back up to the level
            $prices[] = $p;
        }

        $daily = [];

        foreach ($prices as $i => $price) {
            $daily[] = [
                'date' => date('Y-m-d', strtotime("2024-01-01 +{$i} days")),
                'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price,
                'volume' => 1_000_000,
            ];
        }

        $i = count($daily);
        $daily[] = [
            'date' => date('Y-m-d', strtotime("2024-01-01 +{$i} days")),
            'open' => $finalBar['o'] ?? $finalBar['c'],
            'high' => $finalBar['h'] ?? $finalBar['c'] + 1,
            'low' => $finalBar['l'] ?? $finalBar['c'] - 1,
            'close' => $finalBar['c'],
            'volume' => $finalBar['v'] ?? 1_000_000,
        ];

        $benchmark = array_map(fn ($c, $i) => [
            'date' => $c['date'],
            'open' => 100 + $i * 0.05, 'high' => 101 + $i * 0.05,
            'low' => 99 + $i * 0.05, 'close' => 100 + $i * 0.05,
            'volume' => 1_000_000,
        ], $daily, array_keys($daily));

        return app(SwingContextBuilder::class)->build(
            'TESTCO', $daily, [], $benchmark, app(MarketRegimeService::class)->detect($benchmark)
        );
    }

    // ------------------------------------------------------------- MA trend

    public function test_ma_trend_fires_in_a_stacked_rising_trend(): void
    {
        $signal = app(MaTrendStrategy::class)->evaluate($this->context(fn ($i) => 100 + $i * 0.9));

        $this->assertSame(SwingSignal::READY, $signal->state);
        $this->assertNotNull($signal->setup);
        $this->assertSame('BUY', $signal->setup->direction);
    }

    /**
     * The failure mode the intraday MA Crossover demonstrated: averages crossing back and
     * forth in a market that is going nowhere.
     */
    public function test_ma_trend_stands_aside_in_a_sideways_market(): void
    {
        $signal = app(MaTrendStrategy::class)->evaluate(
            $this->context(fn ($i) => 100 + sin($i / 3) * 4)
        );

        $this->assertSame(SwingSignal::NO_SETUP, $signal->state);
        $this->assertNotEmpty($signal->failedChecks());
    }

    public function test_ma_trend_will_not_buy_below_the_long_trend(): void
    {
        $signal = app(MaTrendStrategy::class)->evaluate($this->context(fn ($i) => 400 - $i * 0.9));

        $this->assertSame(SwingSignal::NO_SETUP, $signal->state);
    }

    public function test_ma_trend_enters_on_a_break_of_the_signal_bars_high(): void
    {
        $setup = app(MaTrendStrategy::class)->evaluate($this->context(fn ($i) => 100 + $i * 0.9))->setup;

        $this->assertNotNull($setup->entryTrigger, 'this setup should rest an order, not buy at the open');
        $this->assertGreaterThan($setup->signalPrice, $setup->entryTrigger);
    }

    // ------------------------------------------------------ breakout + volume

    public function test_a_breakout_needs_a_close_above_the_level_not_a_wick(): void
    {
        // Spikes through 150 on heavy volume, then closes back underneath
        $signal = app(BreakoutVolumeStrategy::class)->evaluate(
            $this->resistanceContext(['c' => 148, 'h' => 158, 'l' => 146, 'v' => 3_000_000])
        );

        $this->assertNotSame(SwingSignal::READY, $signal->state, 'a wick through resistance is not a breakout');
    }

    public function test_a_breakout_without_volume_is_not_taken(): void
    {
        $signal = app(BreakoutVolumeStrategy::class)->evaluate(
            $this->resistanceContext(['c' => 153, 'h' => 154, 'l' => 147, 'v' => 400_000])
        );

        $this->assertNotSame(SwingSignal::READY, $signal->state);

        $volumeCheck = collect($signal->checks)->firstWhere('label', 'Volume confirmed');
        $this->assertFalse($volumeCheck['pass'] ?? true);
    }

    public function test_a_breakout_already_far_past_the_level_is_not_chased(): void
    {
        $signal = app(BreakoutVolumeStrategy::class)->evaluate(
            $this->resistanceContext(['c' => 185, 'h' => 186, 'l' => 150, 'v' => 5_000_000])
        );

        $this->assertNotSame(SwingSignal::READY, $signal->state);
    }

    // ------------------------------------------------------- trend pullback

    /** The rule the brief is emphatic about. */
    public function test_a_pullback_touching_the_average_is_not_enough(): void
    {
        // Drifts into the average and closes weakly, near its own low
        $falling = $this->context(fn ($i) => 100 + $i * 0.6, tail: [
            ['c' => 288], ['c' => 284], ['c' => 280],
            ['c' => 276, 'h' => 284, 'l' => 275],   // closes at the bottom of its range
        ]);

        $signal = app(TrendPullbackStrategy::class)->evaluate($falling);

        $this->assertNotSame(SwingSignal::READY, $signal->state);

        $confirmation = collect($signal->checks)->firstWhere('label', 'Bullish confirmation bar');
        $this->assertFalse($confirmation['pass'] ?? true);
    }

    public function test_a_pullback_at_support_without_the_turn_is_a_watchlist_not_a_buy(): void
    {
        $signal = app(TrendPullbackStrategy::class)->evaluate(
            $this->context(fn ($i) => 100 + $i * 0.6, tail: [
                ['c' => 288], ['c' => 284], ['c' => 280],
                ['c' => 277, 'h' => 285, 'l' => 276],
            ])
        );

        $this->assertContains($signal->state, [SwingSignal::WATCHLIST, SwingSignal::NO_SETUP]);

        if ($signal->state === SwingSignal::WATCHLIST) {
            $this->assertNotNull($signal->watchFor);
            $this->assertNull($signal->setup, 'a watchlist entry must not carry a tradeable plan');
        }
    }

    // --------------------------------------------------------- shared rules

    public function test_a_ready_setup_places_its_stop_below_the_entry(): void
    {
        $setup = app(MaTrendStrategy::class)->evaluate($this->context(fn ($i) => 100 + $i * 0.9))->setup;

        $this->assertLessThan($setup->referencePrice(), $setup->stop);
        $this->assertGreaterThan($setup->referencePrice(), $setup->target1);
        $this->assertGreaterThan($setup->target1, $setup->target2);
    }

    /** Targets are derived, so they cannot be mislabelled. */
    public function test_targets_sit_at_the_configured_r_multiples(): void
    {
        config(['swing.risk.target1_r' => 2.0, 'swing.risk.target2_r' => 4.0]);

        $setup = app(MaTrendStrategy::class)->evaluate($this->context(fn ($i) => 100 + $i * 0.9))->setup;

        $this->assertEqualsWithDelta(2.0, $setup->rMultiple($setup->target1), 0.02);
        $this->assertEqualsWithDelta(4.0, $setup->rMultiple($setup->target2), 0.02);
    }

    public function test_every_signal_explains_itself(): void
    {
        foreach ([MaTrendStrategy::class, BreakoutVolumeStrategy::class, TrendPullbackStrategy::class] as $class) {
            $signal = app($class)->evaluate($this->context(fn ($i) => 100 + sin($i / 3) * 4));

            $this->assertNotEmpty($signal->checks, $class . ' returned no checks');

            foreach ($signal->checks as $check) {
                $this->assertArrayHasKey('label', $check);
                $this->assertArrayHasKey('pass', $check);
                $this->assertNotEmpty($check['detail'], $class . ' has a check with no explanation');
            }
        }
    }

    public function test_a_strategy_given_too_little_history_says_so(): void
    {
        $short = $this->context(fn ($i) => 100 + $i, count: 70);

        foreach ([MaTrendStrategy::class, BreakoutVolumeStrategy::class, TrendPullbackStrategy::class] as $class) {
            $this->assertSame(SwingSignal::NO_SETUP, app($class)->evaluate($short)->state);
        }
    }
}
