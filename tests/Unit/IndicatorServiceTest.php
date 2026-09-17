<?php

namespace Tests\Unit;

use App\Services\IndicatorService;
use Tests\TestCase;

class IndicatorServiceTest extends TestCase
{
    protected IndicatorService $indicators;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indicators = new IndicatorService;
    }

    /**
     * Candles whose true ranges are exactly [2, 4, 6, 8, 10].
     *
     * Every bar is centred on 100 and widens by one point each side, so the high-low
     * range is always the largest of the three true-range candidates and the arithmetic
     * can be checked by hand.
     */
    protected function knownTrueRangeCandles(): array
    {
        $candles = [['high' => 100, 'low' => 100, 'close' => 100]];

        for ($i = 1; $i <= 5; $i++) {
            $candles[] = ['high' => 100 + $i, 'low' => 100 - $i, 'close' => 100];
        }

        return $candles;
    }

    public function test_true_ranges_use_the_widest_of_the_three_measures(): void
    {
        $this->assertSame([2, 4, 6, 8, 10], array_map('intval', $this->indicators->trueRanges($this->knownTrueRangeCandles())));
    }

    public function test_true_range_counts_an_overnight_gap(): void
    {
        // A bar that opens and stays far above yesterday's close: its own range is 2,
        // but the distance from yesterday's close is 12, and that is the true range.
        $ranges = $this->indicators->trueRanges([
            ['high' => 100, 'low' => 98, 'close' => 100],
            ['high' => 112, 'low' => 110, 'close' => 111],
        ]);

        $this->assertSame(12.0, (float) $ranges[0]);
    }

    /**
     * Wilder's smoothing over true ranges [2, 4, 6, 8, 10], period 3:
     *   seed = (2 + 4 + 6) / 3            = 4
     *   next = (4 x 2 + 8) / 3            = 5.3333
     *   last = (5.3333 x 2 + 10) / 3      = 6.8889
     *
     * A plain average of the last three would give (6 + 8 + 10) / 3 = 8, so this test
     * fails if the old implementation ever comes back.
     */
    public function test_atr_uses_wilder_smoothing_not_a_plain_average(): void
    {
        $atr = $this->indicators->atr($this->knownTrueRangeCandles(), 3);

        $this->assertEqualsWithDelta(6.8889, $atr, 0.0001);
        $this->assertNotEqualsWithDelta(8.0, $atr, 0.0001);
    }

    public function test_atr_needs_one_more_candle_than_its_period(): void
    {
        $candles = array_slice($this->knownTrueRangeCandles(), 0, 3); // 2 true ranges

        $this->assertNull($this->indicators->atr($candles, 3));
        $this->assertNotNull($this->indicators->atr($this->knownTrueRangeCandles(), 3));
    }

    public function test_atr_of_a_flat_series_is_zero(): void
    {
        $flat = array_fill(0, 20, ['high' => 50, 'low' => 50, 'close' => 50]);

        $this->assertSame(0.0, $this->indicators->atr($flat, 14));
    }

    /** A clean one-way advance is what ADX is meant to score highly. */
    public function test_adx_is_high_in_a_strong_trend(): void
    {
        $candles = [];
        for ($i = 0; $i < 40; $i++) {
            $base = 100 + $i * 2;
            $candles[] = ['high' => $base + 1, 'low' => $base - 1, 'close' => $base + 0.5];
        }

        $result = $this->indicators->adx($candles);

        $this->assertGreaterThan(25, $result['adx']);
        $this->assertGreaterThan($result['minus_di'], $result['plus_di']);
    }

    /** ...and a market going nowhere is exactly what it should score low. */
    public function test_adx_is_low_in_a_sideways_market(): void
    {
        $candles = [];
        for ($i = 0; $i < 40; $i++) {
            $base = 100 + ($i % 2 ? 1 : -1);
            $candles[] = ['high' => $base + 1, 'low' => $base - 1, 'close' => $base];
        }

        $this->assertLessThan(25, $this->indicators->adx($candles)['adx']);
    }

    public function test_adx_direction_flips_in_a_downtrend(): void
    {
        $candles = [];
        for ($i = 0; $i < 40; $i++) {
            $base = 200 - $i * 2;
            $candles[] = ['high' => $base + 1, 'low' => $base - 1, 'close' => $base - 0.5];
        }

        $result = $this->indicators->adx($candles);

        $this->assertGreaterThan(25, $result['adx']);
        $this->assertGreaterThan($result['plus_di'], $result['minus_di']);
    }

    public function test_adx_needs_roughly_twice_its_period(): void
    {
        $candles = array_fill(0, 20, ['high' => 101, 'low' => 99, 'close' => 100]);

        $this->assertNull($this->indicators->adx($candles, 14));
    }

    public function test_swing_highs_find_the_peak(): void
    {
        $highs = [10, 11, 15, 12, 11, 10, 9];
        $candles = array_map(fn ($h) => ['high' => $h, 'low' => $h - 5, 'close' => $h - 1], $highs);

        $pivots = $this->indicators->swingHighs($candles, 2);

        $this->assertCount(1, $pivots);
        $this->assertSame(2, $pivots[0]['index']);
        $this->assertSame(15, $pivots[0]['price']);
    }

    /**
     * The look-ahead guard. A pivot at bar 2 cannot be known at bar 2 — the bars that
     * prove it is a peak print afterwards. Strategies must read `confirmed_at`.
     */
    public function test_a_swing_high_is_only_confirmed_after_its_lookback(): void
    {
        $highs = [10, 11, 15, 12, 11, 10, 9];
        $candles = array_map(fn ($h) => ['high' => $h, 'low' => $h - 5, 'close' => $h - 1], $highs);

        $pivot = $this->indicators->swingHighs($candles, 2)[0];

        $this->assertSame(4, $pivot['confirmed_at']);
        $this->assertGreaterThan($pivot['index'], $pivot['confirmed_at']);
    }

    public function test_swing_lows_find_the_trough(): void
    {
        $lows = [20, 18, 12, 15, 17, 19, 21];
        $candles = array_map(fn ($l) => ['high' => $l + 5, 'low' => $l, 'close' => $l + 1], $lows);

        $pivots = $this->indicators->swingLows($candles, 2);

        $this->assertCount(1, $pivots);
        $this->assertSame(12, $pivots[0]['price']);
    }

    public function test_a_flat_series_has_no_pivots(): void
    {
        $candles = array_fill(0, 20, ['high' => 100, 'low' => 90, 'close' => 95]);

        $this->assertSame([], $this->indicators->swingHighs($candles));
        $this->assertSame([], $this->indicators->swingLows($candles));
    }

    public function test_bollinger_width_narrows_as_volatility_falls(): void
    {
        $wide = [];
        $tight = [];

        for ($i = 0; $i < 20; $i++) {
            $wide[] = 100 + ($i % 2 ? 10 : -10);
            $tight[] = 100 + ($i % 2 ? 1 : -1);
        }

        $this->assertGreaterThan($this->indicators->bollingerWidth($tight), $this->indicators->bollingerWidth($wide));
    }

    public function test_bollinger_width_of_a_flat_series_is_zero(): void
    {
        $this->assertSame(0.0, $this->indicators->bollingerWidth(array_fill(0, 20, 100)));
    }

    public function test_rate_of_change_is_a_percentage(): void
    {
        $this->assertEqualsWithDelta(10.0, $this->indicators->rateOfChange([100, 105, 110], 2), 0.0001);
        $this->assertEqualsWithDelta(-10.0, $this->indicators->rateOfChange([100, 95, 90], 2), 0.0001);
    }

    public function test_relative_strength_is_the_gap_against_the_benchmark(): void
    {
        $stock = [100, 104, 108];      // +8%
        $benchmark = [200, 202, 204];  // +2%

        $this->assertEqualsWithDelta(6.0, $this->indicators->relativeStrength($stock, $benchmark, 2), 0.0001);
    }

    public function test_relative_strength_is_negative_when_the_stock_lags(): void
    {
        $this->assertLessThan(0, $this->indicators->relativeStrength([100, 100, 101], [200, 210, 220], 2));
    }

    public function test_relative_strength_is_undefined_without_enough_history(): void
    {
        $this->assertNull($this->indicators->relativeStrength([100, 101], [200, 202], 20));
    }
}
