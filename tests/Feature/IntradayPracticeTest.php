<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\IntradayAlert;
use App\Models\IntradayBars;
use App\Models\IntradayPick;
use App\Services\ClusteredMean;
use App\Services\IntradayPracticeService;
use App\Services\NseService;
use App\Services\ScreenerService;
use App\Services\StockDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntradayPracticeTest extends TestCase
{
    use RefreshDatabase;

    /** The last session before the day under test; the 24th is a holiday. */
    protected const SESSION = '2026-11-23';

    protected array $symbols = [];

    protected array $meetings = [['symbol' => 'ELSE', 'name' => 'Else', 'purpose' => 'Results', 'date' => '2026-11-26']];

    protected ?array $announcements = [];

    protected array $asked = [];

    protected bool $tradingDay = true;

    protected ?array $intraday = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.token' => 'T', 'services.telegram.chat_id' => '42', 'screener.alerts.enabled' => true,
            'screener.alerts.min_stocks' => 5, 'screener.alerts.picks' => 2,
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->at('2026-11-25 09:00');

        $nse = $this->mock(NseService::class);
        $nse->shouldReceive('isTradingDay')->andReturnUsing(fn () => $this->tradingDay);
        $nse->shouldReceive('boardMeetings')->andReturnUsing(fn () => $this->meetings);
        $nse->shouldReceive('announcements')->andReturnUsing(function ($from, $to) {
            $this->asked[] = [$from->toDateString(), $to->toDateString()];

            return $this->announcements;
        });

        $this->partialMock(ScreenerService::class)->shouldReceive('watchlist')->andReturnUsing(fn () => $this->symbols);

        $data = $this->mock(StockDataService::class);
        $data->shouldReceive('getDailyCandles')->andReturn(null);
        $data->shouldReceive('getIntradayCandles')->andReturnUsing(fn () => $this->intraday);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function at(string $istTime): void
    {
        Carbon::setTestNow(Carbon::parse($istTime, 'Asia/Kolkata')->utc());
    }

    /**
     * 80 weekday bars rising by a fixed share a day, then moving by $dip percent over three days.
     */
    protected function stock(string $symbol, float $daily = 0.5, float $dip = -2.0, array $o = []): void
    {
        $this->symbols[] = $symbol;
        $day = Carbon::parse($o['last'] ?? self::SESSION);
        $dates = [];

        while (count($dates) < ($o['bars'] ?? 80)) {
            if ($day->isWeekday()) {
                array_unshift($dates, $day->toDateString());
            }
            $day->subDay();
        }

        $n = count($dates);
        $price = fn ($i) => 100 * (1 + $daily / 100) ** min($i, $n - 4) * ($i > $n - 4 ? 1 + $dip / 100 * ($i - ($n - 4)) / 3 : 1);
        $fetched = Carbon::parse($o['fetched'] ?? '2026-11-24 16:15', 'Asia/Kolkata')->utc();

        Candle::insert(array_map(fn ($i) => [
            'symbol' => $symbol, 'interval' => '1d', 'date' => $dates[$i], 'open' => $price($i), 'high' => $price($i) * 1.01, 'low' => $price($i) * 0.99,
            'close' => $price($i), 'volume' => $i === $n - 1 ? ($o['last_volume'] ?? 900000) : ($o['volume'] ?? 900000), 'fetched_at' => $fetched,
        ], range(0, $n - 1)));
    }

    /** Ten stocks that go nowhere, so that the top 30% has something to be measured against. */
    protected function flat(int $count = 10): void
    {
        foreach (range(1, $count) as $i) {
            $this->stock("FLAT{$i}", 0.0, 0.0);
        }
    }

    protected function pick(): void
    {
        $this->artisan('screener:picks')->assertSuccessful();
    }

    /** @return array<int, string> */
    protected function picked(string $kind = 'signal'): array
    {
        return IntradayPick::where('kind', $kind)->orderBy('position')->pluck('symbol')->all();
    }

    /** @return array<int, string> */
    protected function messages(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['text'])->all();
    }

    protected function news(string $symbol, string $category, string $text = ''): void
    {
        $this->announcements[] = ['symbol' => $symbol, 'category' => $category, 'text' => $text];
    }

    // -------------------------------------------------------------------- the rule

    public function test_strong_stocks_after_a_small_dip_are_picked_strongest_first(): void
    {
        $this->flat();
        $this->stock('GOOD', 0.4);
        $this->stock('BEST', 0.6);
        $this->stock('THIRD', 0.3);
        $this->pick();

        $this->assertSame(['BEST', 'GOOD'], $this->picked());
        $pick = IntradayPick::firstWhere('symbol', 'BEST');
        $this->assertEqualsWithDelta(-2.0, $pick->return_3, 0.01);
        // 57 rising days inside the 60, then the dip
        $this->assertEqualsWithDelta((1.006 ** 57 * 0.98 - 1) * 100, $pick->return_60, 0.01);
    }

    public function test_the_dip_has_to_be_small_but_real(): void
    {
        $this->flat();
        $this->stock('JUMPED', 0.6, 3.0);
        $this->stock('STILL', 0.6, -0.9);
        $this->stock('CRASHED', 0.6, -6.1);
        $this->stock('EDGE', 0.5, -5.9);
        $this->pick();

        $this->assertSame(['EDGE'], $this->picked());
    }

    public function test_only_the_strongest_share_of_all_stocks_is_considered(): void
    {
        $this->flat(5);
        $this->stock('A', 0.6);
        $this->stock('B', 0.5);
        $this->stock('C', 0.4);
        config(['screener.alerts.picks' => 5, 'screener.alerts.rule.top_share' => 0.25]);
        $this->pick();

        $this->assertSame(['A', 'B'], $this->picked());
    }

    /** Up over 60 days, but already under its own average. */
    public function test_a_broken_trend_is_not_picked(): void
    {
        $this->flat();
        $this->stock('SLOW', 0.05, -5.0);
        $this->stock('GOOD', 0.5);
        config(['screener.alerts.rule.top_share' => 1.0]);
        $this->pick();

        $this->assertSame(['GOOD'], $this->picked());
    }

    /** Back above its 50-day average, but the 20-day average has not followed yet. */
    public function test_a_rebound_is_not_an_uptrend(): void
    {
        $this->flat();
        $this->stock('REBOUND', 0.0, 0.0);
        $bars = Candle::where('symbol', 'REBOUND')->orderBy('date')->get();
        foreach ($bars as $i => $bar) {
            $bar->update(['close' => match (true) { $i < 30 => 80, $i < 60 => 110, $i < 76 => 90, default => [125, 122, 121, 120][$i - 76] }]);
        }
        config(['screener.alerts.rule.top_share' => 1.0]);
        $this->pick();
        $this->assertSame([], $this->picked());

        // The same stock once the 20-day average is above as well
        IntradayPick::query()->delete();
        foreach ($bars as $i => $bar) {
            $i >= 60 && $i < 76 && $bar->update(['close' => 119]);
        }
        $this->pick();
        $this->assertSame(['REBOUND'], $this->picked());
    }

    /** The 20-day average volume leaves the last day out, as in the test of the rule. */
    public function test_thinly_traded_stocks_are_left_out_of_both_groups(): void
    {
        $this->flat();
        $this->stock('THIN', 0.9, -2.0, ['volume' => 499999, 'last_volume' => 50000000]);
        $this->stock('QUIET', 0.5, -2.0, ['volume' => 500000, 'last_volume' => 1]);
        config(['screener.alerts.picks' => 20]);
        $this->pick();

        $this->assertSame(['QUIET'], $this->picked());
        $this->assertNotContains('THIN', $this->picked('random'));
    }

    public function test_too_little_history_is_left_out(): void
    {
        $this->flat();
        $this->stock('NEW', 0.9, -2.0, ['bars' => 60]);
        $this->stock('OLDENOUGH', 0.5, -2.0, ['bars' => 61]);
        $this->pick();

        $this->assertSame(['OLDENOUGH'], $this->picked());
    }

    /** A bar of today is still forming, so it cannot have a say in what to pick today. */
    public function test_todays_bar_is_not_looked_at(): void
    {
        $this->flat();
        $this->stock('GOOD', 0.5);
        Candle::insert(['symbol' => 'GOOD', 'interval' => '1d', 'date' => '2026-11-25', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1,
            'volume' => 1, 'fetched_at' => now()]);
        $this->pick();

        $this->assertSame(['GOOD'], $this->picked());
    }

    public function test_a_stock_that_did_not_trade_in_the_last_session_is_left_out(): void
    {
        $this->flat();
        $this->stock('SUSPENDED', 0.9, -2.0, ['last' => '2026-11-20']);
        config(['screener.alerts.picks' => 20]);
        $this->pick();

        $this->assertSame([], $this->picked());
        $this->assertNotContains('SUSPENDED', $this->picked('random'));
    }

    public function test_a_stock_whose_prices_cannot_be_refreshed_is_left_out(): void
    {
        $this->flat();
        $this->stock('STALE', 0.9, -2.0, ['fetched' => '2026-11-24 15:59']);
        $this->stock('GOOD', 0.5);
        $this->pick();

        $this->assertSame(['GOOD'], $this->picked());
    }

    // -------------------------------------------------------------------- comparison

    public function test_random_stocks_are_different_ones_and_the_same_on_every_run(): void
    {
        $this->flat();
        $this->stock('GOOD', 0.5);
        $this->pick();
        $first = $this->picked('random');

        $this->assertCount(2, $first);
        $this->assertNotContains('GOOD', $first);

        IntradayPick::query()->delete();
        $this->pick();
        $this->assertSame($first, $this->picked('random'));
    }

    public function test_random_stocks_change_from_day_to_day(): void
    {
        $this->flat(30);
        $this->pick();
        $first = $this->picked('random');

        IntradayPick::query()->delete();
        $this->at('2026-11-26 09:00');
        Candle::query()->update(['fetched_at' => now()]);
        $this->pick();

        $this->assertNotSame($first, $this->picked('random'));
    }

    // -------------------------------------------------------------------- news

    public function test_a_board_meeting_today_removes_a_stock_and_the_next_one_takes_its_place(): void
    {
        $this->flat();
        $this->stock('BEST', 0.6);
        $this->stock('GOOD', 0.5);
        $this->stock('THIRD', 0.4);
        $this->meetings[] = ['symbol' => 'BEST', 'name' => 'Best', 'purpose' => 'Financial Results', 'date' => '2026-11-25'];
        $this->meetings[] = ['symbol' => 'GOOD', 'name' => 'Good', 'purpose' => 'Dividend', 'date' => '2026-11-26'];
        $this->pick();

        $this->assertSame(['GOOD', 'THIRD'], $this->picked());
        $this->assertStringContainsString('Left out because of news: BEST (Financial Results)', $this->messages()[0]);
    }

    public function test_risky_announcements_remove_a_stock_from_both_groups(): void
    {
        $this->flat(6);
        $this->stock('GOOD', 0.5);
        $this->news('GOOD', 'Resignation of Statutory Auditor');
        $this->news('FLAT1', 'Updates', 'A show cause notice was received');
        $this->news('FLAT2', 'Credit Rating- Revision');
        $this->news('FLAT3', 'Analysts/Institutional Investor Meet/Con. Call Updates', 'Firework of ideas'); // not "fire"
        $this->news('FLAT4', 'Credit Rating', 'Reaffirmed');
        config(['screener.alerts.picks' => 20]);
        $this->pick();

        $this->assertSame([], $this->picked());
        $this->assertEqualsCanonicalizing(['FLAT3', 'FLAT4', 'FLAT5', 'FLAT6'], $this->picked('random'));
    }

    /** With a holiday in between, yesterday is not where the news starts. */
    public function test_news_is_read_from_the_last_session_until_now(): void
    {
        $this->flat();
        $this->pick();

        $this->assertSame([['2026-11-23', '2026-11-25']], $this->asked);
        $this->assertStringContainsString('News checked', $this->messages()[0]);
    }

    public function test_the_message_says_when_the_news_could_not_be_checked(): void
    {
        $this->flat();
        $this->announcements = null;
        $this->pick();

        $this->assertStringContainsString('The news could not be checked', $this->messages()[0]);
        $this->assertStringNotContainsString('News checked', $this->messages()[0]);
    }

    /** No meeting in a whole week means the list was not read. */
    public function test_an_empty_list_of_meetings_counts_as_unchecked(): void
    {
        $this->flat();
        $this->meetings = [];
        $this->pick();

        $this->assertStringContainsString('The news could not be checked', $this->messages()[0]);
    }

    public function test_the_exchange_feed_is_read_and_a_refusal_is_not_silence(): void
    {
        $nse = new NseService;
        $row = ['symbol' => 'tcs', 'desc' => 'Resignation', 'attchmntText' => 'x', 'an_dt' => '25-Nov-2026 08:00:00'];
        Http::fake(['www.nseindia.com/*' => Http::sequence()->push([$row])->push([])->push([], 403)->push([], 403)->push([], 403)]);

        $this->assertSame([['symbol' => 'TCS', 'category' => 'Resignation', 'text' => 'x']], $nse->announcements(now(), now()));

        $this->assertNull($nse->announcements(now()->addDays(1), now()->addDays(1)), 'an empty list');
        $this->assertNull($nse->announcements(now()->addDays(2), now()->addDays(2)), 'a refusal');
    }

    public function test_holidays_come_from_the_exchange(): void
    {
        $nse = new NseService;
        Http::fake(['www.nseindia.com/*' => Http::response(['CM' => [['tradingDate' => '24-Nov-2026']]])]);

        $this->assertFalse($nse->isTradingDay(Carbon::parse('2026-11-24')));
        $this->assertTrue($nse->isTradingDay(Carbon::parse('2026-11-25')));
        $this->assertFalse($nse->isTradingDay(Carbon::parse('2026-11-28')), 'a Saturday');
    }

    public function test_an_unreadable_holiday_list_counts_as_open(): void
    {
        Http::fake(['www.nseindia.com/*' => Http::response('blocked', 403)]);

        $this->assertTrue((new NseService)->isTradingDay(Carbon::parse('2026-11-24')));
    }

    // -------------------------------------------------------------------- the message and the command

    public function test_the_watchlist_is_sent_once_and_says_practice(): void
    {
        $this->flat();
        $this->stock('GOOD', 0.5);
        $this->pick();
        $this->pick();

        $this->assertCount(1, $this->messages());
        $text = $this->messages()[0];
        $this->assertStringContainsString("📋 PRACTICE — today's watchlist: 1 stock(s)", $text);
        $this->assertMatchesRegularExpression('/1\. GOOD \(₹[\d.,]+\) — strong stock \(\+\d+% in 60 days\) after a small dip \(-2\.0% in 3 days\)/', $text);
        $this->assertStringContainsString('Do not buy from this list', $text);
        $this->assertStringContainsString('No real money', $text);
        foreach ($this->picked('random') as $symbol) {
            $this->assertStringNotContainsString($symbol, $text);
        }
    }

    public function test_a_second_run_never_changes_the_picks(): void
    {
        $this->flat();
        $this->stock('GOOD', 0.5);
        $this->pick();
        $this->stock('BETTER', 0.9);
        $this->pick();

        $this->assertSame(['GOOD'], $this->picked());
    }

    public function test_an_undelivered_watchlist_is_sent_by_the_next_run(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true])]);
        $this->flat();
        $this->stock('GOOD', 0.5);

        $this->artisan('screener:picks')->expectsOutputToContain('could not be sent');
        $this->artisan('screener:picks')->expectsOutputToContain('Watchlist sent');
        $this->assertCount(2, $this->messages());
    }

    public function test_a_day_without_a_fitting_stock_says_so(): void
    {
        $this->flat();
        $this->pick();

        $this->assertStringContainsString('no stock fits the rule', $this->messages()[0]);
        $this->assertCount(2, $this->picked('random'));
    }

    public function test_a_scan_without_fresh_prices_fails_loudly_and_once(): void
    {
        $this->flat(4);

        $this->artisan('screener:picks')->assertFailed();
        $this->artisan('screener:picks')->assertFailed();

        $this->assertSame(0, IntradayPick::count());
        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('Only 4 stocks have fresh prices', $this->messages()[0]);
    }

    public function test_nothing_is_picked_when_off_closed_or_too_late(): void
    {
        $this->flat();

        config(['screener.alerts.enabled' => false]);
        $this->pick();
        config(['screener.alerts.enabled' => true]);

        $this->tradingDay = false;
        $this->pick();
        $this->tradingDay = true;

        $this->at('2026-11-25 15:00');
        $this->pick();

        $this->assertSame(0, IntradayPick::count());
        $this->assertSame([], $this->messages());
    }

    public function test_the_jobs_can_be_started_from_the_web_with_the_token(): void
    {
        config(['screener.backtest_token' => 'right']);

        foreach (['screener-picks', 'intraday-store'] as $job) {
            $this->getJson("/api/run-{$job}?token=wrong")->assertForbidden();
            $this->getJson("/api/{$job}-status")->assertOk();
        }
    }

    // -------------------------------------------------------------------- results

    protected function trade(string $date, float $r, string $kind = 'signal', string $symbol = 'TCS', string $status = 'TARGET'): void
    {
        IntradayAlert::create(['alert_date' => $date, 'kind' => $kind, 'symbol' => $symbol, 'price_time' => '10:00', 'entry' => 100, 'max_entry' => 101,
            'stop_loss' => 99, 'target' => 102, 'quantity' => 10, 'reason' => 'x', 'candle_at' => 1, 'seen_low' => 99, 'seen_high' => 101, 'status' => $status,
            'net_pnl' => $status === 'OPEN' ? null : $r * 1000, 'r_multiple' => $status === 'OPEN' ? null : $r]);
    }

    /** @param array<int, float> $signal one result a week */
    protected function weeks(array $signal, array $random): array
    {
        foreach ($signal as $i => $r) {
            $day = Carbon::parse('2026-01-05')->addWeeks($i)->toDateString();
            $this->trade($day, $r);
            $this->trade($day, $random[$i], 'random', 'RAND');
        }

        return app(IntradayPracticeService::class)->report();
    }

    public function test_open_trades_are_not_counted(): void
    {
        $this->trade('2026-11-25', 0, status: 'OPEN');
        $this->trade('2026-11-25', -1.1, symbol: 'INFY', status: 'STOP');
        $report = app(IntradayPracticeService::class)->report();

        $this->assertSame(1, $report['signal']['trades']);
        $this->assertSame(-1.1, $report['signal']['average_r']);
        $this->assertSame(-1100.0, $report['signal']['net_pnl']);
        $this->assertSame(0, $report['random']['trades']);
    }

    public function test_nothing_is_concluded_before_ten_weeks(): void
    {
        $report = $this->weeks(array_fill(0, 9, 1.5), array_fill(0, 9, -1.0));

        $this->assertNull($report['signal']['range']);
        $this->assertSame('Too early to judge: 9 of 10 weeks.', $report['verdict']);
    }

    public function test_ten_days_are_not_ten_weeks(): void
    {
        foreach (range(0, 11) as $i) {
            $this->trade(Carbon::parse('2026-11-02')->addWeekdays($i)->toDateString(), 1.0);
        }

        $this->assertSame(3, app(IntradayPracticeService::class)->report()['weeks']);
    }

    public function test_the_comparison_needs_ten_weeks_of_random_trades_too(): void
    {
        $weeks = fn (int $n) => collect(range(1, $n))->mapWithKeys(fn ($i) => ["w{$i}" => collect([$i / 10])]);

        $this->assertNull(ClusteredMean::differenceRange($weeks(10), $weeks(9), 10));
        $this->assertNull(ClusteredMean::differenceRange($weeks(9), $weeks(10), 10));
        $this->assertNotNull(ClusteredMean::differenceRange($weeks(10), $weeks(10), 10));
    }

    public function test_profit_alone_is_not_proof_and_neither_is_beating_random(): void
    {
        $wave = fn (float $base) => array_map(fn ($i) => $base + ($i % 2 ? 0.1 : -0.1), range(0, 9));

        $this->assertStringContainsString('Profitable and better than random', $this->weeks($wave(0.5), $wave(-0.5))['verdict']);
        IntradayAlert::query()->delete();
        $this->assertStringContainsString('not clearly better than random', $this->weeks($wave(0.5), $wave(0.5))['verdict']);
        IntradayAlert::query()->delete();
        $this->assertStringContainsString('not clearly profitable after charges', $this->weeks($wave(-0.2), $wave(-0.9))['verdict']);
        IntradayAlert::query()->delete();
        $this->assertStringContainsString('Do not trade this', $this->weeks($wave(-0.2), $wave(-0.2))['verdict']);
    }

    public function test_one_lucky_week_does_not_make_the_result_clear(): void
    {
        $report = $this->weeks([20, -0.5, -0.5, -0.5, -0.5, -0.5, -0.5, -0.5, -0.5, -0.5], array_fill(0, 10, -0.3));

        $this->assertGreaterThan(0, $report['signal']['average_r']);
        $this->assertLessThan(0, $report['signal']['range'][0]);
        $this->assertStringContainsString('Do not trade this', $report['verdict']);
    }

    public function test_the_difference_cancels_what_moves_both_groups(): void
    {
        $market = [1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 1.0, -1.0, 1.0, -1.0];
        $weeks = fn (float $extra) => collect($market)->mapWithKeys(fn ($m, $i) => ["w{$i}" => collect([$m + $extra])]);

        $this->assertSame([0.2, 0.2], ClusteredMean::differenceRange($weeks(0.2), $weeks(0.0), 10));
        $this->assertLessThan(0, ClusteredMean::range($weeks(0.2), 10)[0]);
    }

    /** Two trades in one week are one observation: the range is that of the weekly averages. */
    public function test_the_range_counts_weeks_not_trades(): void
    {
        $single = collect(range(0, 9))->mapWithKeys(fn ($i) => ["w{$i}" => collect([$i / 10])]);
        $double = $single->map(fn ($week) => collect([$week[0], $week[0]]));

        $this->assertSame(ClusteredMean::range($single, 10), ClusteredMean::range($double, 10));
        // mean 0.45, sd of the weekly results 0.30277, over the root of ten weeks
        $this->assertSame([round(0.45 - 1.96 * 0.302765 / sqrt(10), 3), round(0.45 + 1.96 * 0.302765 / sqrt(10), 3)], ClusteredMean::range($single, 10));
    }

    public function test_the_page_shows_picks_trades_and_the_comparison_but_not_random_stocks(): void
    {
        $this->flat();
        $this->stock('GOOD', 0.5);
        $this->pick();
        $this->trade('2026-11-25', -1.1, symbol: 'SOLD', status: 'STOP');
        $this->trade('2026-11-25', 0.4, 'random', 'HIDDEN');

        $page = $this->get('/intraday/practice')->assertOk();
        $page->assertSee('PRACTICE — no money was traded')->assertSee('GOOD')->assertSee('SOLD')->assertDontSee('HIDDEN')
            ->assertSee('Too early to judge: 1 of 10 weeks.')->assertSee('Random stocks');
        $this->getJson('/intraday/practice')->assertOk()->assertJsonPath('random.trades', 1);
    }

    public function test_the_page_works_before_anything_has_happened(): void
    {
        $this->get('/intraday/practice')->assertOk()->assertSee('No practice trade yet');
    }

    // -------------------------------------------------------------------- 5-minute prices

    protected function bars(string $date, float $price = 100.0): array
    {
        return array_map(fn ($i) => ['timestamp' => Carbon::parse("{$date} 09:15", 'Asia/Kolkata')->addMinutes($i * 5)->timestamp,
            'open' => $price + $i, 'high' => $price + $i + 0.555, 'low' => $price + $i - 0.5, 'close' => $price + $i + 0.25, 'volume' => 1000 + $i], range(0, 74));
    }

    public function test_finished_days_are_stored_and_read_back_exactly(): void
    {
        $this->at('2026-11-25 16:00');
        $this->symbols = ['TCS'];
        $this->intraday = [...$this->bars('2026-11-23'), ...$this->bars('2026-11-25', 200)];
        $this->artisan('intraday:store')->expectsOutputToContain('2 day(s)')->assertSuccessful();

        $row = IntradayBars::where('symbol', 'TCS')->where('date', '2026-11-25')->first();
        $this->assertSame(75, $row->count);
        $this->assertCount(75, $row->candles());
        $this->assertEquals(['timestamp' => $this->intraday[75]['timestamp'], 'open' => 200, 'high' => 200.56, 'low' => 199.5, 'close' => 200.25, 'volume' => 1000], $row->candles()[0]);
        $this->assertLessThan(3000, strlen($row->data));
    }

    public function test_a_day_still_trading_is_not_stored(): void
    {
        $this->at('2026-11-25 15:59');
        $this->symbols = ['TCS'];
        $this->intraday = [...$this->bars('2026-11-23'), ...$this->bars('2026-11-25')];
        $this->artisan('intraday:store');

        $this->assertSame(['2026-11-23'], IntradayBars::pluck('date')->map(fn ($d) => substr($d, 0, 10))->all());
    }

    public function test_storing_again_replaces_rather_than_repeats(): void
    {
        $this->at('2026-11-25 16:00');
        $this->symbols = ['TCS'];
        $this->intraday = $this->bars('2026-11-25');
        $this->artisan('intraday:store');
        $this->intraday = $this->bars('2026-11-25', 300);
        $this->artisan('intraday:store');

        $this->assertSame(1, IntradayBars::count());
        $this->assertEquals(300, IntradayBars::first()->candles()[0]['open']);
    }

    public function test_storing_nothing_is_a_failure(): void
    {
        $this->symbols = ['TCS'];
        $this->artisan('intraday:store')->assertFailed();
    }
}
