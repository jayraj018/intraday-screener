<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Services\CandleStore;
use App\Services\StockDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandleStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function bars(array $dates): array
    {
        return array_map(fn ($d, $i) => [
            'date' => $d,
            'open' => 100 + $i, 'high' => 105 + $i, 'low' => 99 + $i, 'close' => 104 + $i,
            'adj_close' => 103 + $i, 'volume' => 1000000 + $i,
        ], $dates, array_keys($dates));
    }

    protected function storeReturning(array $bars): CandleStore
    {
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->andReturn($bars);

        return new CandleStore($data);
    }

    public function test_sync_stores_candles(): void
    {
        $store = $this->storeReturning($this->bars(['2026-09-14', '2026-09-15', '2026-09-16']));

        $this->assertSame(3, $store->sync('RELIANCE'));
        $this->assertSame(3, Candle::count());
        $this->assertSame('RELIANCE', Candle::first()->symbol);
    }

    /**
     * Re-syncing an overlapping range must correct bars in place. A provider revises a
     * bar after the close, and duplicating it would put two versions of the same day into
     * the backtest.
     */
    public function test_resyncing_updates_instead_of_duplicating(): void
    {
        $this->storeReturning($this->bars(['2026-09-14', '2026-09-15']))->sync('RELIANCE');

        $revised = $this->bars(['2026-09-14', '2026-09-15']);
        $revised[1]['close'] = 999.5;

        $this->storeReturning($revised)->sync('RELIANCE');

        $this->assertSame(2, Candle::count());
        $this->assertSame(999.5, Candle::where('date', '2026-09-15')->first()->close);
    }

    public function test_get_returns_candles_oldest_first_in_the_indicator_shape(): void
    {
        $store = $this->storeReturning($this->bars(['2026-09-16', '2026-09-14', '2026-09-15']));
        $store->sync('RELIANCE');

        $candles = $store->get('RELIANCE');

        $this->assertSame(['2026-09-14', '2026-09-15', '2026-09-16'], array_column($candles, 'date'));
        $this->assertSame(['date', 'open', 'high', 'low', 'close', 'adj_close', 'volume'], array_keys($candles[0]));
    }

    /**
     * The point-in-time guarantee a walk-forward replay depends on: asking for everything
     * up to a past date must not return a single bar from after it.
     */
    public function test_get_up_to_a_date_cannot_leak_a_later_bar(): void
    {
        $store = $this->storeReturning($this->bars(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17']));
        $store->sync('RELIANCE');

        $candles = $store->get('RELIANCE', '1d', to: '2026-09-15');

        $this->assertCount(2, $candles);
        $this->assertSame('2026-09-15', end($candles)['date']);
    }

    public function test_latest_date_reports_how_far_the_store_has_got(): void
    {
        $store = $this->storeReturning($this->bars(['2026-09-14', '2026-09-15']));

        $this->assertNull($store->latestDate('RELIANCE'));

        $store->sync('RELIANCE');

        $this->assertSame('2026-09-15', substr((string) $store->latestDate('RELIANCE'), 0, 10));
    }

    public function test_weekly_and_daily_are_stored_separately(): void
    {
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->andReturn($this->bars(['2026-09-14', '2026-09-15']));
        $data->shouldReceive('getWeeklyCandles')->andReturn($this->bars(['2026-09-14']));
        $store = new CandleStore($data);

        $store->sync('RELIANCE', '1d');
        $store->sync('RELIANCE', '1wk');

        $this->assertCount(2, $store->get('RELIANCE', '1d'));
        $this->assertCount(1, $store->get('RELIANCE', '1wk'));
    }

    public function test_only_date_keyed_intervals_can_be_stored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storeReturning([])->sync('RELIANCE', '5m');
    }

    public function test_a_symbol_the_provider_cannot_fetch_writes_nothing(): void
    {
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->andReturn(null);

        $this->assertSame(0, (new CandleStore($data))->sync('NOSUCHTICKER'));
        $this->assertSame(0, Candle::count());
    }
}
