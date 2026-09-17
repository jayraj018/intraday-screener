<?php

namespace Tests\Unit;

use App\Services\IndicatorService;
use App\Services\NseService;
use App\Services\ScreenerService;
use App\Services\StockDataService;
use Tests\TestCase;

/**
 * A target's R label has to come from the same arithmetic that produced the target.
 * The failure this guards against is a display that calls a 2R target "1.2R" — or a
 * setup whose stop and target don't actually sit at the configured multiple of risk,
 * which quietly corrupts every expectancy number downstream.
 */
class RiskRewardLabellingTest extends TestCase
{
    protected function screener(): ScreenerService
    {
        return new ScreenerService(
            $this->createMock(StockDataService::class),
            new IndicatorService,
            $this->createMock(NseService::class),
        );
    }

    protected function rMultiple(float $entry, float $stop, float $target): float
    {
        return round(abs($target - $entry) / abs($entry - $stop), 4);
    }

    /** The exact case from the brief: entry 500, stop 480, so 540 is 2R and nothing else. */
    public function test_the_worked_example_labels_540_as_two_r(): void
    {
        $this->assertSame(2.0, $this->rMultiple(500.0, 480.0, 540.0));
        $this->assertNotSame(1.2, $this->rMultiple(500.0, 480.0, 540.0));
        $this->assertSame(1.0, $this->rMultiple(500.0, 480.0, 520.0));
    }

    public function test_long_setups_sit_at_the_configured_risk_reward(): void
    {
        config(['screener.risk_reward_ratio' => 2, 'screener.stop_loss_atr_multiplier' => 1.5]);

        $setup = $this->buildSetup('BUY', entry: 500.0, atr: 10.0);

        // Risk is 1.5 x ATR below entry; the target is twice that above it
        $this->assertSame(485.0, $setup['stop_loss']);
        $this->assertSame(530.0, $setup['target']);
        $this->assertSame(2.0, $this->rMultiple($setup['entry'], $setup['stop_loss'], $setup['target']));
    }

    public function test_short_setups_sit_at_the_configured_risk_reward(): void
    {
        config(['screener.risk_reward_ratio' => 2, 'screener.stop_loss_atr_multiplier' => 1.5]);

        $setup = $this->buildSetup('SELL (short)', entry: 500.0, atr: 10.0);

        $this->assertSame(515.0, $setup['stop_loss']);
        $this->assertSame(470.0, $setup['target']);
        $this->assertSame(2.0, $this->rMultiple($setup['entry'], $setup['stop_loss'], $setup['target']));
    }

    public function test_changing_the_configured_ratio_moves_the_target(): void
    {
        config(['screener.risk_reward_ratio' => 3, 'screener.stop_loss_atr_multiplier' => 1.5]);

        $setup = $this->buildSetup('BUY', entry: 500.0, atr: 10.0);

        $this->assertSame(3.0, $this->rMultiple($setup['entry'], $setup['stop_loss'], $setup['target']));
    }

    /**
     * Drive the real setup builder through dailySetups() by way of a crafted series, so
     * this tests the shipped arithmetic rather than a copy of it.
     */
    protected function buildSetup(string $signal, float $entry, float $atr): array
    {
        $screener = $this->screener();
        $method = new \ReflectionMethod($screener, 'buildSetup');

        return $method->invoke(
            $screener,
            'TEST', 'Unit', $signal, $entry, $atr,
            config('screener.stop_loss_atr_multiplier'),
            config('screener.risk_reward_ratio'),
            false,
            str_contains($signal, 'BUY') ? 'below' : 'above',
            'test'
        );
    }
}
