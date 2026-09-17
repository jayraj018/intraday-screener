<?php

namespace Tests\Feature;

use App\Services\ScreenerService;
use App\Services\StockDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The search page and the screener must produce the same setup for the same stock.
 *
 * They did not. Each had its own copy of the six daily strategies, and the search page's
 * copy hardcoded 9, 21, 1.5 and 2.0 where the screener read config — so changing a config
 * value moved one and left the other behind, and the same stock could show one entry on
 * the dashboard and a different one on its own analysis page.
 */
class StrategyConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** 60 quiet days, then a jump big enough to trigger a setup. */
    protected function candles(): array
    {
        $candles = [];

        for ($i = 0; $i < 59; $i++) {
            $candles[] = [
                'date' => now()->subDays(60 - $i)->toDateString(),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100,
                'volume' => 1_000_000,
            ];
        }

        $candles[] = [
            'date' => now()->subDay()->toDateString(),
            'open' => 100, 'high' => 106, 'low' => 100, 'close' => 105,
            'volume' => 3_000_000,
        ];

        return $candles;
    }

    protected function analyze(array $candles): array
    {
        $this->mock(StockDataService::class, function ($mock) use ($candles) {
            $mock->shouldReceive('getDailyCandles')->andReturn($candles);
            $mock->shouldReceive('getIntradayCandles')->andReturn(null);
            $mock->shouldReceive('lastError')->andReturn(null);
        });

        return $this->getJson('/screener/analyze?symbol=TESTCO')->assertOk()->json();
    }

    public function test_the_search_page_and_the_screener_produce_identical_setups(): void
    {
        $candles = $this->candles();

        $fromService = app(ScreenerService::class)->dailySetups(
            'TESTCO', $candles, app(ScreenerService::class)->dailyContext($candles, requireLiquidity: false)
        );

        $fromPage = $this->analyze($candles)['setups'];

        // Cast the prices: JSON turns 105.0 into 105, which assertSame would call a
        // difference even though the two paths agree to the paisa.
        $compare = fn ($setups) => array_map(fn ($s) => [
            $s['strategy'], $s['signal'],
            (float) $s['entry'], (float) $s['stop_loss'], (float) $s['target'],
        ], $setups);

        $this->assertNotEmpty($fromPage, 'the crafted series should trigger at least one setup');
        $this->assertSame($compare($fromService), $compare($fromPage));
    }

    /**
     * The regression that started all this: a config change has to move both, or neither.
     */
    public function test_changing_the_stop_multiplier_moves_the_search_page_too(): void
    {
        $candles = $this->candles();

        config(['screener.stop_loss_atr_multiplier' => 1.5]);
        $tight = $this->analyze($candles)['setups'][0]['stop_loss'];

        config(['screener.stop_loss_atr_multiplier' => 4.0]);
        $wide = $this->analyze($candles)['setups'][0]['stop_loss'];

        $this->assertNotSame($tight, $wide, 'the search page ignored the configured stop distance');
        $this->assertLessThan($tight, $wide, 'a larger multiplier must put the stop further away');
    }

    public function test_changing_the_moving_average_periods_moves_the_search_page_too(): void
    {
        $candles = $this->candles();

        config(['screener.short_ma' => 9, 'screener.long_ma' => 21]);
        $default = $this->analyze($candles)['indicators']['ma'];

        config(['screener.short_ma' => 5, 'screener.long_ma' => 50]);
        $changed = $this->analyze($candles)['indicators']['ma'];

        $this->assertNotSame($default['short_val'], $changed['short_val']);
        $this->assertStringContainsString('5 SMA', $changed['detail']);
        $this->assertStringContainsString('50 SMA', $changed['detail']);
    }

    /** The frontend reads these by name; renaming one silently empties a panel. */
    public function test_the_analysis_response_keeps_its_shape(): void
    {
        $body = $this->analyze($this->candles());

        $this->assertSame(
            ['ma', 'rsi', 'bb', 'macd', 'volume'],
            array_keys($body['indicators'])
        );

        foreach ([['ma', 'short_val'], ['rsi', 'value'], ['bb', 'upper'], ['macd', 'signal'], ['volume', 'current']] as [$group, $field]) {
            $this->assertArrayHasKey('status', $body['indicators'][$group]);
            $this->assertArrayHasKey('detail', $body['indicators'][$group]);
            $this->assertArrayHasKey($field, $body['indicators'][$group]);
        }

        $this->assertContains($body['bias'], ['BULLISH', 'BEARISH', 'NEUTRAL']);
        $this->assertIsInt($body['bullish_indicators']);
    }

    /**
     * The liquidity floor picks which stocks are worth scanning; it is not a strategy
     * rule. A thinly traded stock the screener skips must still analyse when searched.
     */
    public function test_an_illiquid_stock_still_analyses_when_searched(): void
    {
        $candles = array_map(fn ($c) => [...$c, 'volume' => 1000], $this->candles());

        $this->assertNull(app(ScreenerService::class)->dailyContext($candles), 'the screener should skip it');
        $this->assertNotEmpty($this->analyze($candles)['setups'], 'but searching it directly should still work');
    }
}
