<?php

namespace Tests\Feature;

use App\Services\Swing\MarketRegimeService;
use App\Services\Swing\RelativeStrengthService;
use App\Services\Swing\SwingContextBuilder;
use Tests\TestCase;

class SwingContextTest extends TestCase
{
    /**
     * @param  callable  $priceAt  fn(int $i): float
     */
    protected function series(int $count, callable $priceAt, int $volume = 1_000_000, string $start = '2024-01-01'): array
    {
        $candles = [];

        for ($i = 0; $i < $count; $i++) {
            $price = $priceAt($i);
            $candles[] = [
                'date' => date('Y-m-d', strtotime("{$start} +{$i} days")),
                'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price,
                'volume' => $volume,
            ];
        }

        return $candles;
    }

    // ------------------------------------------------------------ market regime

    public function test_a_rising_market_reads_as_a_bullish_trend(): void
    {
        $regime = app(MarketRegimeService::class)->detect($this->series(300, fn ($i) => 100 + $i * 0.8));

        $this->assertSame('BULLISH_TREND', $regime['trend']);
        $this->assertGreaterThan(25, $regime['adx']);
    }

    public function test_a_falling_market_reads_as_a_bearish_trend(): void
    {
        $regime = app(MarketRegimeService::class)->detect($this->series(300, fn ($i) => 400 - $i * 0.8));

        $this->assertSame('BEARISH_TREND', $regime['trend']);
    }

    /** The reading that matters most: a market going nowhere must not look like a trend. */
    public function test_a_choppy_market_reads_as_sideways(): void
    {
        $regime = app(MarketRegimeService::class)->detect(
            $this->series(300, fn ($i) => 100 + ($i % 2 ? 2 : -2))
        );

        $this->assertSame('SIDEWAYS', $regime['trend']);
        $this->assertLessThan(25, $regime['adx']);
    }

    public function test_too_little_history_is_unknown_rather_than_guessed(): void
    {
        $regime = app(MarketRegimeService::class)->detect($this->series(10, fn ($i) => 100 + $i));

        $this->assertSame('UNKNOWN', $regime['trend']);
        $this->assertNull($regime['adx']);
    }

    /** Breadth data does not exist in this app, and the regime says so rather than assuming. */
    public function test_breadth_is_reported_as_unavailable_not_neutral(): void
    {
        $this->assertSame('NOT_AVAILABLE', app(MarketRegimeService::class)->detect($this->series(300, fn ($i) => 100 + $i))['breadth']);
    }

    public function test_volatility_is_judged_against_the_markets_own_history(): void
    {
        // Calm for a long stretch, then a violent finish
        $candles = $this->series(300, fn ($i) => 100 + $i * 0.1);

        for ($i = 280; $i < 300; $i++) {
            $candles[$i]['high'] = $candles[$i]['close'] + 15;
            $candles[$i]['low'] = $candles[$i]['close'] - 15;
        }

        $this->assertSame('HIGH_VOLATILITY', app(MarketRegimeService::class)->detect($candles)['volatility']);
    }

    // -------------------------------------------------------- relative strength

    public function test_a_stock_outpacing_the_index_is_leading(): void
    {
        $rs = app(RelativeStrengthService::class)->compare(
            $this->series(150, fn ($i) => 100 * (1 + $i * 0.004)),   // faster
            $this->series(150, fn ($i) => 100 * (1 + $i * 0.001)),   // slower
        );

        $this->assertTrue($rs['leading']);
        $this->assertGreaterThan(0, $rs['periods'][20]);
    }

    public function test_a_stock_lagging_the_index_is_not_leading(): void
    {
        $rs = app(RelativeStrengthService::class)->compare(
            $this->series(150, fn ($i) => 100 * (1 + $i * 0.0005)),
            $this->series(150, fn ($i) => 100 * (1 + $i * 0.004)),
        );

        $this->assertFalse($rs['leading']);
        $this->assertLessThan(0, $rs['periods'][20]);
    }

    /**
     * The correctness trap: a stock with missing sessions has fewer bars than the index.
     * Comparing by position would measure two different windows and return a plausible
     * number that means nothing, so the two series are lined up by date first.
     */
    public function test_series_are_aligned_by_date_not_by_position(): void
    {
        $benchmark = $this->series(150, fn ($i) => 100 + $i);
        $stock = $this->series(150, fn ($i) => 100 + $i);

        // The stock was halted for 30 sessions in the middle
        $gapped = array_merge(array_slice($stock, 0, 40), array_slice($stock, 70));

        $rs = app(RelativeStrengthService::class)->compare($gapped, $benchmark);

        $this->assertSame(count($gapped), $rs['aligned_bars'], 'only shared dates should be compared');
        $this->assertLessThan(count($benchmark), $rs['aligned_bars']);
    }

    public function test_relative_strength_is_null_where_history_is_too_short(): void
    {
        $rs = app(RelativeStrengthService::class)->compare(
            $this->series(30, fn ($i) => 100 + $i),
            $this->series(30, fn ($i) => 100 + $i),
        );

        $this->assertNotNull($rs['periods'][20]);
        $this->assertNull($rs['periods'][100], 'a 100-day span cannot be measured on 30 bars');
    }

    // ------------------------------------------------------------------ context

    public function test_the_context_bundles_what_a_strategy_needs(): void
    {
        $daily = $this->series(300, fn ($i) => 100 + $i * 0.5);
        $benchmark = $this->series(300, fn ($i) => 100 + $i * 0.2);
        $weekly = $this->series(60, fn ($i) => 100 + $i * 2);
        $regime = app(MarketRegimeService::class)->detect($benchmark);

        $context = app(SwingContextBuilder::class)->build('TESTCO', $daily, $weekly, $benchmark, $regime);

        $this->assertSame('TESTCO', $context->symbol);
        $this->assertSame(end($daily)['date'], $context->date());
        $this->assertSame(end($daily)['close'], $context->close());
        $this->assertTrue($context->isLeadingTheMarket());
        $this->assertTrue($context->weeklyTrendIsUp());
        $this->assertNotNull($context->atrPercent());
        $this->assertEqualsWithDelta(1.0, $context->relativeVolume(), 0.01);
    }

    /** A strategy is handed a series that already ends at the bar being judged. */
    public function test_the_context_cannot_see_past_its_decision_bar(): void
    {
        $full = $this->series(300, fn ($i) => 100 + $i * 0.5);
        $upTo = array_slice($full, 0, 200);
        $benchmark = array_slice($this->series(300, fn ($i) => 100 + $i * 0.2), 0, 200);
        $regime = app(MarketRegimeService::class)->detect($benchmark);

        $context = app(SwingContextBuilder::class)->build('TESTCO', $upTo, [], $benchmark, $regime);

        $this->assertSame($full[199]['date'], $context->date());
        $this->assertCount(200, $context->daily);
        $this->assertNotContains($full[250]['date'], array_column($context->daily, 'date'));
    }

    public function test_a_stock_without_enough_history_gets_no_context(): void
    {
        $short = $this->series(20, fn ($i) => 100 + $i);

        $this->assertNull(app(SwingContextBuilder::class)->build('NEWCO', $short, [], $short, []));
    }

    public function test_weekly_trend_is_unknown_rather_than_forced(): void
    {
        $daily = $this->series(300, fn ($i) => 100 + $i * 0.5);
        $regime = app(MarketRegimeService::class)->detect($daily);

        // No weekly candles at all — the answer is "not known", not "down"
        $context = app(SwingContextBuilder::class)->build('TESTCO', $daily, [], $daily, $regime);

        $this->assertSame('UNKNOWN', $context->weeklyTrend);
        $this->assertFalse($context->weeklyTrendIsUp());
    }
}
