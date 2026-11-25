<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Services\CandleStore;
use App\Services\StockDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CandleStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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

    // --------------------------------------------------------------- freshness

    protected function at(string $istTime): void
    {
        Carbon::setTestNow(Carbon::parse($istTime, 'Asia/Kolkata')->utc());
    }

    /**
     * The bug this exists for: a bar downloaded at 10:02 was stored as that day's candle
     * and never looked at again, so from the next day it passed for a finished session.
     */
    public function test_a_bar_stored_mid_session_is_replaced_once_the_session_is_over(): void
    {
        $this->at('2026-11-23 10:02');
        $partial = $this->bars(['2026-11-20', '2026-11-23']);
        $partial[1]['close'] = 104.5;
        $partial[1]['volume'] = 3_785_835;
        $this->storeReturning($partial)->current('RELIANCE');

        $this->at('2026-11-24 16:15');
        $final = $this->bars(['2026-11-20', '2026-11-23']);
        $final[1]['close'] = 103.25;
        $final[1]['volume'] = 15_392_522;

        $candles = $this->storeReturning($final)->current('RELIANCE');

        $this->assertSame(103.25, end($candles)['close']);
        $this->assertSame(15_392_522, end($candles)['volume']);
    }

    public function test_candles_fetched_since_the_close_are_not_downloaded_again(): void
    {
        $this->at('2026-11-23 16:30');
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->once()->andReturn($this->bars(['2026-11-20', '2026-11-23']));
        $store = new CandleStore($data);

        $store->current('RELIANCE');
        $this->at('2026-11-24 09:00');   // before the next close: still the latest there is
        $store->current('RELIANCE');

        $this->assertCount(2, $store->get('RELIANCE'));
    }

    public function test_a_weekend_does_not_make_fridays_candles_stale(): void
    {
        $this->at('2026-11-20 16:30');   // Friday
        $store = $this->storeReturning($this->bars(['2026-11-19', '2026-11-20']));
        $store->sync('RELIANCE');

        $this->at('2026-11-22 12:00');   // Sunday
        $this->assertTrue($store->isCurrent('RELIANCE'));

        $this->at('2026-11-23 16:30');   // Monday, after the close
        $this->assertFalse($store->isCurrent('RELIANCE'));
    }

    /** A signal on old prices is worse than no signal. */
    public function test_candles_that_cannot_be_refreshed_are_withheld(): void
    {
        $this->at('2026-11-20 16:30');
        $this->storeReturning($this->bars(['2026-11-19', '2026-11-20']))->sync('RELIANCE');

        $this->at('2026-11-23 16:30');
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->andReturn(null);

        $this->assertSame([], (new CandleStore($data))->current('RELIANCE'));
    }

    /**
     * After a 2:1 split the provider halves every past price. Refreshing only the recent
     * tail would leave older bars unhalved, and the join would look like a 50% crash.
     */
    public function test_a_split_reloads_the_whole_history(): void
    {
        $this->at('2026-11-20 16:30');
        $dates = ['2026-06-01', '2026-06-02', '2026-11-19', '2026-11-20'];
        $this->storeReturning($this->bars($dates))->sync('RELIANCE');

        $halved = array_map(function ($b) {
            foreach (['open', 'high', 'low', 'close', 'adj_close'] as $f) {
                $b[$f] /= 2;
            }

            return $b;
        }, $this->bars($dates));

        $this->at('2026-11-23 16:30');
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->with('RELIANCE', '3mo')->once()->andReturn(array_slice($halved, 2));
        $data->shouldReceive('getDailyCandles')->with('RELIANCE', '2y')->once()->andReturn($halved);

        $closes = array_column((new CandleStore($data))->current('RELIANCE'), 'close');

        $this->assertSame([52.0, 52.5, 53.0, 53.5], $closes);
    }

    public function test_an_ordinary_refresh_does_not_reload_the_history(): void
    {
        $this->at('2026-11-20 10:00');
        $this->storeReturning($this->bars(['2026-11-19', '2026-11-20']))->sync('RELIANCE');

        $this->at('2026-11-23 16:30');
        $bars = $this->bars(['2026-11-19', '2026-11-20', '2026-11-23']);
        $bars[1]['close'] = 130;         // the unfinished bar moved a lot: not a split
        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->once()->andReturn($bars);

        $this->assertCount(3, (new CandleStore($data))->current('RELIANCE'));
    }

    // ------------------------------------------------------------ weekly dates

    /**
     * A finished week is stamped Monday 00:00 IST, which is Sunday 18:30 in UTC. The
     * forming week is stamped with the moment of the request.
     */
    public function test_weekly_candles_are_filed_under_their_monday(): void
    {
        $monday = Carbon::parse('2026-11-16 00:00', 'Asia/Kolkata')->timestamp;
        $midWeek = Carbon::parse('2026-11-25 10:02', 'Asia/Kolkata')->timestamp;

        Http::fake(['*' => Http::response(['chart' => ['result' => [[
            'timestamp' => [$monday, $midWeek],
            'indicators' => ['quote' => [[
                'open' => [1, 2], 'high' => [3, 4], 'low' => [1, 2], 'close' => [2, 3], 'volume' => [10, 20],
            ]]],
        ]]]])]);

        $candles = app(StockDataService::class)->getWeeklyCandles('RELIANCE');

        $this->assertSame(['2026-11-16', '2026-11-23'], array_column($candles, 'date'));
    }

    public function test_the_migration_moves_stored_weeks_to_their_monday(): void
    {
        $row = fn ($symbol, $interval, $date, $close) => DB::table('candles')->insert([
            'symbol' => $symbol, 'interval' => $interval, 'date' => $date, 'open' => 1, 'high' => 1, 'low' => 1,
            'close' => $close, 'volume' => 1, 'fetched_at' => now(),
        ]);

        $row('AAA', '1wk', '2026-11-08', 10);   // Sunday: finished week
        $row('AAA', '1wk', '2026-11-15', 20);   // Sunday: finished week...
        $row('AAA', '1wk', '2026-11-16', 99);   // ...and a half-formed copy of the same one
        $row('AAA', '1wk', '2026-11-18', 98);   // mid-week snapshot
        $row('BBB', '1wk', '2026-11-16', 30);   // already right
        $row('AAA', '1d', '2026-11-15', 77);    // daily rows are not touched

        (require database_path('migrations/2026_11_24_000000_file_weekly_candles_under_their_monday.php'))->up();

        $stored = Candle::where('interval', '1wk')->orderBy('symbol')->orderBy('date')->get()
            ->map(fn ($c) => [$c->symbol, $c->date->toDateString(), $c->close])->all();

        $this->assertSame([['AAA', '2026-11-09', 10.0], ['AAA', '2026-11-16', 20.0], ['BBB', '2026-11-16', 30.0]], $stored);
        $this->assertSame(1, Candle::where('interval', '1d')->count());
    }
}
