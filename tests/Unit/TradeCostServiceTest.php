<?php

namespace Tests\Unit;

use App\Services\TradeCostService;
use Tests\TestCase;

class TradeCostServiceTest extends TestCase
{
    protected function costs(array $overrides = []): TradeCostService
    {
        config(['screener.costs' => array_merge([
            'capital' => 100000,
            'risk_per_trade_percent' => 1.0,
            'max_position_value' => 100000,
            'brokerage_percent' => 0.03,
            'brokerage_cap' => 20,
            'stt_sell_percent' => 0.025,
            'exchange_txn_percent' => 0.00297,
            'sebi_percent' => 0.0001,
            'stamp_duty_buy_percent' => 0.003,
            'gst_percent' => 18,
        ], $overrides)]);

        config(['screener.execution.slippage_bps' => 5]);

        return new TradeCostService;
    }

    public function test_quantity_is_sized_from_the_risk_budget(): void
    {
        // ₹100,000 x 1% = ₹1,000 of risk, ₹10 per share => 100 shares
        $this->assertSame(100, $this->costs()->quantity(500.0, 10.0));
    }

    public function test_quantity_is_capped_by_position_value(): void
    {
        // Risk allows 1,000 shares but ₹100,000 / ₹500 only affords 200
        $this->assertSame(200, $this->costs()->quantity(500.0, 1.0));
    }

    public function test_a_stop_wider_than_the_risk_budget_is_untradeable(): void
    {
        $this->assertSame(0, $this->costs()->quantity(500.0, 1500.0));
    }

    public function test_slippage_moves_the_fill_against_the_trader(): void
    {
        $costs = $this->costs();

        $this->assertGreaterThan(100.0, $costs->slip(100.0, buying: true));
        $this->assertLessThan(100.0, $costs->slip(100.0, buying: false));
    }

    /**
     * The worked example from the audit: 100 shares bought at 306.00 and sold at 307.60
     * is a ₹160 gross win that costs about ₹33 to make.
     */
    public function test_round_trip_costs_match_a_hand_calculation(): void
    {
        $costs = $this->costs()->roundTripCosts(306.00, 307.60, 100, isBuyFirst: true);

        $this->assertEqualsWithDelta(32.55, $costs, 0.5);
    }

    public function test_costs_are_charged_on_a_short_the_same_way(): void
    {
        // STT follows the sale whichever leg it is, so a short's costs sit in the same range
        $long = $this->costs()->roundTripCosts(306.00, 307.60, 100, isBuyFirst: true);
        $short = $this->costs()->roundTripCosts(307.60, 306.00, 100, isBuyFirst: false);

        $this->assertEqualsWithDelta($long, $short, 1.0);
    }

    public function test_no_costs_are_charged_when_the_trade_could_not_be_taken(): void
    {
        $this->assertSame(0.0, $this->costs()->roundTripCosts(306.00, 307.60, 0, isBuyFirst: true));
    }
}
