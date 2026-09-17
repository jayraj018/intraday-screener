<?php

namespace Tests\Feature;

use App\Services\Swing\SwingBacktestService;
use App\Services\Swing\SwingSetup;
use Tests\TestCase;

class SwingBacktestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No slippage and no charges unless a test is about them, so the arithmetic under
        // test is visible rather than buried in rounding.
        config([
            'swing.execution.slippage_bps' => 0,
            'swing.costs.brokerage_percent' => 0,
            'swing.costs.brokerage_cap' => 0,
            'swing.costs.stt_buy_percent' => 0,
            'swing.costs.stt_sell_percent' => 0,
            'swing.costs.exchange_txn_percent' => 0,
            'swing.costs.sebi_percent' => 0,
            'swing.costs.stamp_duty_buy_percent' => 0,
            'swing.costs.gst_percent' => 0,
        ]);
    }

    protected function walker(): SwingBacktestService
    {
        return app(SwingBacktestService::class);
    }

    /** Entry 500, stop 480, so risk is 20 and target 1 at 540 is exactly 2R. */
    protected function plan(array $overrides = []): SwingSetup
    {
        return new SwingSetup(
            symbol: $overrides['symbol'] ?? 'TESTCO',
            strategy: 'Trend Pullback',
            direction: $overrides['direction'] ?? 'BUY',
            signalDate: '2026-01-01',
            signalPrice: $overrides['signalPrice'] ?? 500.0,
            stop: $overrides['stop'] ?? 480.0,
            stopMethod: 'swing_low',
            target1: $overrides['target1'] ?? 540.0,
            target2: $overrides['target2'] ?? null,
        );
    }

    /** @param array<int, array{o: float, h: float, l: float, c: float}> $bars */
    protected function candles(array $bars): array
    {
        $candles = [['date' => '2026-01-01', 'open' => 500, 'high' => 505, 'low' => 495, 'close' => 500, 'volume' => 1000000]];

        foreach ($bars as $i => $b) {
            $candles[] = [
                'date' => sprintf('2026-01-%02d', $i + 2),
                'open' => $b['o'], 'high' => $b['h'], 'low' => $b['l'], 'close' => $b['c'],
                'volume' => 1000000,
            ];
        }

        return $candles;
    }

    // ---------------------------------------------------------------- entry

    public function test_entry_is_the_next_sessions_open_not_the_signal_price(): void
    {
        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 502, 'h' => 545, 'l' => 500, 'c' => 542],
        ]), 0);

        $this->assertSame(502.0, $trade->entryPrice);
        $this->assertSame('2026-01-02', $trade->entryDate);
    }

    public function test_a_signal_on_the_last_bar_cannot_be_traded(): void
    {
        $this->assertNull($this->walker()->walk($this->plan(), $this->candles([]), 0));
    }

    public function test_an_entry_that_gaps_too_far_is_treated_as_missed(): void
    {
        config(['swing.execution.max_entry_gap_percent' => 2.0]);

        // Opens at 520, 4% above the 500 signal — the plan's risk is no longer the risk
        $this->assertNull($this->walker()->walk($this->plan(), $this->candles([
            ['o' => 520, 'h' => 545, 'l' => 515, 'c' => 540],
        ]), 0));
    }

    public function test_an_entry_that_gaps_below_the_stop_is_never_taken(): void
    {
        config(['swing.execution.max_entry_gap_percent' => 50.0]);

        $this->assertNull($this->walker()->walk($this->plan(), $this->candles([
            ['o' => 470, 'h' => 475, 'l' => 460, 'c' => 465],
        ]), 0));
    }

    // ---------------------------------------------------------------- exits

    public function test_a_target_hit_closes_the_trade_at_the_target(): void
    {
        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 520, 'l' => 498, 'c' => 515],
            ['o' => 515, 'h' => 545, 'l' => 512, 'c' => 542],
        ]), 0);

        $this->assertSame('target1', $trade->exitReason());
        $this->assertSame(540.0, $trade->exitPrice());
        $this->assertSame(2, $trade->barsHeld);
        $this->assertEqualsWithDelta(2.0, $trade->rMultiple, 0.01);
    }

    public function test_a_stop_hit_closes_the_trade_at_the_stop(): void
    {
        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 502, 'l' => 475, 'c' => 478],
        ]), 0);

        $this->assertSame('stop', $trade->exitReason());
        $this->assertSame(480.0, $trade->exitPrice());
        $this->assertEqualsWithDelta(-1.0, $trade->rMultiple, 0.01);
    }

    public function test_a_position_still_open_is_closed_at_the_holding_limit(): void
    {
        config(['swing.exits.max_holding_days' => 3]);

        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 505, 'l' => 498, 'c' => 503],
            ['o' => 503, 'h' => 508, 'l' => 500, 'c' => 506],
            ['o' => 506, 'h' => 510, 'l' => 504, 'c' => 509],
            ['o' => 509, 'h' => 545, 'l' => 505, 'c' => 542],  // never reached
        ]), 0);

        $this->assertSame('max_holding_days', $trade->exitReason());
        $this->assertSame(509.0, $trade->exitPrice());
        $this->assertSame(3, $trade->barsHeld);
    }

    public function test_a_strategy_can_invalidate_its_own_idea(): void
    {
        $trade = $this->walker()->walk(
            $this->plan(),
            $this->candles([
                ['o' => 500, 'h' => 505, 'l' => 498, 'c' => 503],
                ['o' => 503, 'h' => 506, 'l' => 495, 'c' => 496],
            ]),
            0,
            isInvalidated: fn ($bar) => $bar['close'] < 500,
        );

        $this->assertSame('trend_invalidated', $trade->exitReason());
        $this->assertSame(496.0, $trade->exitPrice());
    }

    // ---------------------------------------------------------------- gaps

    /**
     * The rule the brief singles out: stop at 500, yesterday closed at 510, today opens
     * at 490. The exit is 490. Recording 500 would book money that was never available.
     */
    public function test_a_gap_through_the_stop_fills_at_the_open_not_the_stop(): void
    {
        $trade = $this->walker()->walk(new SwingSetup(
            symbol: 'TESTCO', strategy: 'Trend Pullback', direction: 'BUY',
            signalDate: '2026-01-01', signalPrice: 500.0,
            stop: 500.0, stopMethod: 'swing_low', target1: 560.0,
        ), $this->candles([
            ['o' => 505, 'h' => 512, 'l' => 503, 'c' => 510],
            ['o' => 490, 'h' => 492, 'l' => 485, 'c' => 488],
        ]), 0);

        $this->assertSame('stop_gap', $trade->exitReason());
        $this->assertSame(490.0, $trade->exitPrice());
        $this->assertLessThan(-1.0, $trade->rMultiple, 'a gap must be able to lose more than 1R');
    }

    public function test_the_unrealistic_gap_rule_is_available_for_comparison(): void
    {
        config(['swing.execution.gap_fill' => 'level']);

        $trade = $this->walker()->walk(new SwingSetup(
            symbol: 'TESTCO', strategy: 'Trend Pullback', direction: 'BUY',
            signalDate: '2026-01-01', signalPrice: 500.0,
            stop: 500.0, stopMethod: 'swing_low', target1: 560.0,
        ), $this->candles([
            ['o' => 505, 'h' => 512, 'l' => 503, 'c' => 510],
            ['o' => 490, 'h' => 492, 'l' => 485, 'c' => 488],
        ]), 0);

        $this->assertSame(500.0, $trade->exitPrice(), 'the optimistic rule pretends the stop was available');
    }

    public function test_a_favourable_gap_fills_better_than_the_target(): void
    {
        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 520, 'l' => 498, 'c' => 518],
            ['o' => 555, 'h' => 560, 'l' => 552, 'c' => 558],  // gaps past the 540 target
        ]), 0);

        $this->assertSame('target_gap', $trade->exitReason());
        $this->assertSame(555.0, $trade->exitPrice());
    }

    // ---------------------------------------------- same-candle ambiguity

    public function test_a_candle_reaching_both_levels_counts_as_the_stop_by_default(): void
    {
        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 545, 'l' => 475, 'c' => 510],  // touched 540 and 480
        ]), 0);

        $this->assertSame('stop', $trade->exitReason());
        $this->assertSame(480.0, $trade->exitPrice());
    }

    public function test_the_optimistic_same_candle_rule_is_available_for_comparison(): void
    {
        config(['swing.exits.same_candle' => 'target_first']);

        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 545, 'l' => 475, 'c' => 510],
        ]), 0);

        $this->assertSame('target1', $trade->exitReason());
    }

    // ---------------------------------------------------------------- trailing

    public function test_a_breakeven_trail_turns_a_loser_into_a_scratch(): void
    {
        config(['swing.exits.trailing.method' => 'breakeven_after_1r']);

        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 525, 'l' => 498, 'c' => 522],  // closes past 1R (520)
            ['o' => 520, 'h' => 521, 'l' => 470, 'c' => 475],  // collapses
        ]), 0);

        $this->assertSame('trailing_stop', $trade->exitReason());
        $this->assertSame(500.0, $trade->exitPrice(), 'the stop should have moved to entry');
        $this->assertEqualsWithDelta(0.0, $trade->rMultiple, 0.01);
    }

    public function test_the_stop_never_moves_against_the_trade(): void
    {
        config(['swing.exits.trailing.method' => 'breakeven_after_1r']);

        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 525, 'l' => 498, 'c' => 522],  // trail moves to 500
            ['o' => 520, 'h' => 522, 'l' => 502, 'c' => 505],  // pulls back, stop must hold
            ['o' => 505, 'h' => 508, 'l' => 495, 'c' => 498],  // takes out 500
        ]), 0);

        $this->assertSame(500.0, $trade->exitPrice());
    }

    // ---------------------------------------------------------------- scaling

    public function test_scaling_out_takes_part_at_target_one_and_runs_the_rest(): void
    {
        config([
            'swing.exits.scale_out_at_target1' => 0.5,
            'swing.exits.max_holding_days' => 5,
        ]);

        $trade = $this->walker()->walk($this->plan(['target2' => 580.0]), $this->candles([
            ['o' => 500, 'h' => 545, 'l' => 498, 'c' => 542],  // target 1
            ['o' => 545, 'h' => 585, 'l' => 543, 'c' => 582],  // target 2
        ]), 0);

        $this->assertCount(2, $trade->exits);
        $this->assertSame('target1', $trade->exits[0]->reason);
        $this->assertSame('target2', $trade->exits[1]->reason);
        $this->assertSame($trade->quantity, $trade->exits[0]->quantity + $trade->exits[1]->quantity);
        $this->assertGreaterThan(2.0, $trade->rMultiple, 'running the second half past 2R should beat a flat 2R');
    }

    // ---------------------------------------------------------------- shorts

    public function test_a_short_stops_out_above_its_entry(): void
    {
        $short = new SwingSetup(
            symbol: 'TESTCO', strategy: 'Trend Pullback', direction: 'SELL',
            signalDate: '2026-01-01', signalPrice: 500.0,
            stop: 520.0, stopMethod: 'swing_low', target1: 460.0,
        );

        $trade = $this->walker()->walk($short, $this->candles([
            ['o' => 500, 'h' => 525, 'l' => 498, 'c' => 522],
        ]), 0);

        $this->assertSame('stop', $trade->exitReason());
        $this->assertEqualsWithDelta(-1.0, $trade->rMultiple, 0.01);
    }

    public function test_a_short_profits_when_price_falls_to_its_target(): void
    {
        $short = new SwingSetup(
            symbol: 'TESTCO', strategy: 'Trend Pullback', direction: 'SELL',
            signalDate: '2026-01-01', signalPrice: 500.0,
            stop: 520.0, stopMethod: 'swing_low', target1: 460.0,
        );

        $trade = $this->walker()->walk($short, $this->candles([
            ['o' => 500, 'h' => 502, 'l' => 455, 'c' => 462],
        ]), 0);

        $this->assertSame('target1', $trade->exitReason());
        $this->assertEqualsWithDelta(2.0, $trade->rMultiple, 0.01);
    }

    // ---------------------------------------------------------------- costs

    /** Delivery pays STT on both legs, which intraday does not. */
    public function test_delivery_charges_are_taken_off_the_result(): void
    {
        config(['swing.costs' => array_merge(config('swing.costs'), [
            'stt_buy_percent' => 0.1,
            'stt_sell_percent' => 0.1,
            'stamp_duty_buy_percent' => 0.015,
            'gst_percent' => 18,
            'exchange_txn_percent' => 0.00297,
            'sebi_percent' => 0.0001,
        ])]);

        $trade = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 500, 'h' => 545, 'l' => 498, 'c' => 542],
        ]), 0);

        $this->assertGreaterThan(0, $trade->costs);
        $this->assertLessThan($trade->grossPnl, $trade->netPnl);
        $this->assertLessThan(2.0, $trade->rMultiple, 'a 2R gross target is under 2R once charges are paid');
    }

    public function test_the_stored_row_keeps_signal_entry_and_exit_apart(): void
    {
        $row = $this->walker()->walk($this->plan(), $this->candles([
            ['o' => 502, 'h' => 545, 'l' => 500, 'c' => 542],
        ]), 0)->toArray();

        $this->assertSame('swing', $row['system']);
        $this->assertSame(500.0, (float) $row['signal_price']);
        $this->assertSame(502.0, (float) $row['entry_price']);
        $this->assertSame(540.0, (float) $row['exit_price']);
        $this->assertNotSame($row['signal_price'], $row['entry_price']);
    }

    /** The R label can only come from the plan, so it cannot be mislabelled. */
    public function test_r_multiples_are_derived_from_the_plan(): void
    {
        $setup = $this->plan(['target2' => 560.0]);

        $this->assertSame(2.0, $setup->rMultiple(540.0));
        $this->assertSame(3.0, $setup->rMultiple(560.0));
        $this->assertSame(1.0, $setup->rMultiple(520.0));
        $this->assertNotSame(1.2, $setup->rMultiple(540.0));
    }
}
