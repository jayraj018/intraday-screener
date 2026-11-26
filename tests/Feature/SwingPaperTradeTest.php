<?php

namespace Tests\Feature;

use App\Models\SwingPaperTrade;
use App\Services\CandleStore;
use App\Services\StockDataService;
use App\Services\Swing\PaperTradeService;
use App\Services\Swing\PaperTradeStatsService;
use App\Services\Swing\SwingBacktestService;
use App\Services\Swing\SwingScannerService;
use App\Services\Swing\SwingSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwingPaperTradeTest extends TestCase
{
    use RefreshDatabase;

    /** Monday, so the sessions that follow are 24, 25, 26, 27, 30 November... */
    protected const SIGNAL = '2026-11-23';

    protected array $bars = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->at('2026-11-23 16:30');
        $this->bars = [$this->bar('2026-11-23', 495, 499, 492, 498)];

        $this->mock(StockDataService::class, function ($mock) {
            $mock->shouldReceive('getDailyCandles')->andReturnUsing(fn () => $this->bars);
            $mock->shouldReceive('getWeeklyCandles')->andReturn([]);
        });
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

    protected function bar(string $date, float $o, float $h, float $l, float $c): array
    {
        return ['date' => $date, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'adj_close' => $c, 'volume' => 1_000_000];
    }

    /** Entry above 500, stop 480, target 540. */
    protected function plan(array $o = []): array
    {
        return [
            'setup' => new SwingSetup($o['symbol'] ?? 'TESTCO', $o['strategy'] ?? 'MA Trend Following', 'BUY', $o['date'] ?? self::SIGNAL,
                498.0, 480.0, 'atr', 540.0, 580.0, entryTrigger: $o['trigger'] ?? 500.0),
            'score' => $o['score'] ?? 70,
            'regime' => 'SIDEWAYS',
        ];
    }

    protected function record(array $plans = [], array $sessions = [self::SIGNAL], string $kind = SwingPaperTrade::SIGNAL): array
    {
        return app(PaperTradeService::class)->record($plans ?: [$this->plan()], $sessions, $kind);
    }

    /** Print a session, move the clock past its settled close, and resolve. */
    protected function tradeDay(string $date, float $o, float $h, float $l, float $c): SwingPaperTrade
    {
        $this->bars[] = $this->bar($date, $o, $h, $l, $c);
        $this->at("{$date} 16:30");
        app(PaperTradeService::class)->resolve();

        return SwingPaperTrade::first();
    }

    // ------------------------------------------------------------------ writing

    public function test_a_plan_is_written_down_in_full_before_its_session(): void
    {
        $this->assertSame(['logged' => 1, 'late' => 0, 'taken' => 1], $this->record());

        $trade = SwingPaperTrade::first();
        $this->assertSame('PENDING', $trade->status);
        $this->assertSame([500.0, 510.0, 480.0, 540.0], [$trade->entry_trigger, $trade->max_entry, $trade->stop_price, $trade->target1]);
        $this->assertSame(10, $trade->rules['swing.exits']['max_holding_days']);
        $this->assertNull($trade->r_multiple);
        $this->assertFalse($trade->late);
    }

    /** The first version is the only one written without knowing what came after. */
    public function test_logging_the_same_plan_again_changes_nothing(): void
    {
        $this->record();
        $before = SwingPaperTrade::first()->toArray();

        $this->at('2026-11-23 20:00');
        $again = $this->record([$this->plan(['trigger' => 505.0, 'score' => 99])]);

        $this->assertSame(0, $again['logged']);
        $this->assertSame($before, SwingPaperTrade::first()->toArray());
    }

    public function test_a_plan_logged_after_its_session_opened_is_marked_late_and_not_taken(): void
    {
        $this->at('2026-11-24 09:28');
        $result = $this->record();

        $this->assertSame(['logged' => 1, 'late' => 1, 'taken' => 0], $result);
        $this->assertNull(SwingPaperTrade::first()->portfolio);
    }

    public function test_a_plan_logged_before_the_open_is_on_time(): void
    {
        $this->at('2026-11-24 08:55');

        $this->assertSame(0, $this->record()['late']);
    }

    public function test_a_plan_whose_session_has_already_passed_is_late(): void
    {
        // Early on the 25th, but the 24th has already traded
        $this->at('2026-11-25 08:00');

        $this->assertSame(1, $this->record(sessions: [self::SIGNAL, '2026-11-24'])['late']);
    }

    /** A holiday between the signal and the session does not make the plan late. */
    public function test_a_holiday_in_between_is_not_a_missed_session(): void
    {
        $this->at('2026-11-25 08:00');

        $this->assertSame(0, $this->record(sessions: [self::SIGNAL])['late']);
    }

    // ---------------------------------------------------------------- resolving

    public function test_nothing_is_decided_before_the_next_session_closes(): void
    {
        $this->record();
        $this->bars[] = $this->bar('2026-11-24', 499, 545, 470, 505);

        // 15:40: the exchange has closed but its figures are not final
        $this->at('2026-11-24 15:40');
        app(PaperTradeService::class)->resolve();

        $this->assertSame('PENDING', SwingPaperTrade::first()->status);
    }

    public function test_a_winning_plan_is_closed_at_its_target_after_costs(): void
    {
        $this->record();

        $open = $this->tradeDay('2026-11-24', 499, 520, 498, 515);
        $this->assertSame('OPEN', $open->status);
        $this->assertEqualsWithDelta(500 * (1 + config('swing.execution.slippage_bps') / 10000), $open->entry_price, 0.001);
        $this->assertNull($open->r_multiple);

        $closed = $this->tradeDay('2026-11-25', 515, 545, 512, 542);
        $this->assertSame(['CLOSED', 'target1', 2], [$closed->status, $closed->exit_reason, $closed->bars_held]);
        $this->assertSame('2026-11-25', $closed->exit_date->toDateString());
        $this->assertGreaterThan(0, (float) $closed->costs);
        $this->assertLessThan(2.0, $closed->r_multiple);
        $this->assertGreaterThan(1.7, $closed->r_multiple);
    }

    public function test_a_losing_plan_is_closed_at_its_stop(): void
    {
        $this->record();
        $this->tradeDay('2026-11-24', 499, 505, 495, 500);
        $closed = $this->tradeDay('2026-11-25', 498, 499, 475, 478);

        $this->assertSame(['CLOSED', 'stop'], [$closed->status, $closed->exit_reason]);
        $this->assertLessThan(-1.0, $closed->r_multiple);
    }

    public function test_an_order_that_is_not_reached_expires_after_one_session(): void
    {
        $this->record();
        $trade = $this->tradeDay('2026-11-24', 495, 499.95, 490, 497);

        $this->assertSame(['NO_ENTRY', 'not_triggered'], [$trade->status, $trade->exit_reason]);
        $this->assertSame('NO_ENTRY', $this->tradeDay('2026-11-25', 500, 520, 499, 515)->status);
    }

    public function test_an_open_above_the_maximum_price_is_not_chased(): void
    {
        $this->record();
        $trade = $this->tradeDay('2026-11-24', 511, 520, 509, 515);

        $this->assertSame(['NO_ENTRY', 'gapped_too_far'], [$trade->status, $trade->exit_reason]);
    }

    public function test_a_position_closes_on_its_tenth_session_and_not_before(): void
    {
        $this->record();
        $days = ['2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-30', '2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-07'];

        foreach (array_slice($days, 0, 9) as $day) {
            $this->assertSame('OPEN', $this->tradeDay($day, 502, 510, 495, 505)->status, $day);
        }

        $closed = $this->tradeDay($days[9], 502, 510, 495, 505);
        $this->assertSame(['CLOSED', 'max_holding_days', 10], [$closed->status, $closed->exit_reason, $closed->bars_held]);
    }

    /** The log must agree with the backtest, or comparing the two means nothing. */
    public function test_the_result_is_the_one_the_backtest_gives(): void
    {
        $this->record();
        $this->tradeDay('2026-11-24', 499, 520, 498, 515);
        $closed = $this->tradeDay('2026-11-25', 515, 545, 512, 542);

        $trade = app(SwingBacktestService::class)->walk($this->plan()['setup'], $this->bars, 0);

        $this->assertEqualsWithDelta($trade->rMultiple, $closed->r_multiple, 0.0001);
        $this->assertSame($trade->quantity, $closed->quantity);
        $this->assertEqualsWithDelta($trade->netPnl, $closed->net_pnl, 0.01);
    }

    public function test_changing_the_settings_does_not_change_a_trade_already_logged(): void
    {
        $this->record();
        config(['swing.exits.max_holding_days' => 1, 'swing.costs.risk_per_trade_percent' => 5.0, 'swing.execution.max_entry_gap_percent' => 0.0]);

        $this->assertSame('OPEN', $this->tradeDay('2026-11-24', 501, 520, 498, 515)->status);
        $closed = $this->tradeDay('2026-11-25', 515, 545, 512, 542);

        $this->assertSame('target1', $closed->exit_reason);
        $this->assertLessThanOrEqual(1000 / 20, $closed->quantity);
        $this->assertSame(1, config('swing.exits.max_holding_days'), 'the settings were not put back');
    }

    /** A 1:2 split halves every stored price; the plan has to halve with it. */
    public function test_a_split_during_the_trade_does_not_look_like_a_crash(): void
    {
        $this->record();
        $this->tradeDay('2026-11-24', 499, 520, 498, 515);

        $this->bars = array_map(fn ($b) => ['date' => $b['date'], 'open' => $b['open'] / 2, 'high' => $b['high'] / 2, 'low' => $b['low'] / 2,
            'close' => $b['close'] / 2, 'adj_close' => $b['close'] / 2, 'volume' => 2_000_000], $this->bars);

        $closed = $this->tradeDay('2026-11-25', 257.5, 272.5, 256, 271);

        $this->assertSame(['CLOSED', 'target1'], [$closed->status, $closed->exit_reason]);
        $this->assertEqualsWithDelta(0.5, $closed->price_factor, 0.0001);
        $this->assertGreaterThan(1.7, $closed->r_multiple);
        $this->assertSame(500.0, $closed->entry_trigger, 'the logged plan must not be rewritten');
    }

    public function test_a_stock_that_cannot_be_refreshed_is_left_waiting(): void
    {
        $this->record();
        app(CandleStore::class)->freshen('TESTCO');
        $this->bars = [];
        $this->at('2026-11-24 16:30');

        $this->assertSame(['opened' => 0, 'closed' => 0, 'no_entry' => 0], app(PaperTradeService::class)->resolve());
        $this->assertSame('PENDING', SwingPaperTrade::first()->status);
    }

    // ---------------------------------------------------------------- portfolio

    public function test_the_paper_account_takes_the_most_trusted_five_stocks_once_each(): void
    {
        $plans = [];

        // Ranking on its own, before swing.trust leaves anything out
        config(['swing.trust.watch_only' => []]);

        // S1-S4 Trend Pullback with high scores, S5-S8 MA Trend with low ones
        foreach (range(1, 8) as $i) {
            $plans[] = $this->plan(['symbol' => "S{$i}", 'strategy' => $i <= 4 ? 'Trend Pullback' : 'MA Trend Following', 'score' => $i <= 4 ? 95 : 40]);
        }

        // Two independent families on S2, still not MA Trend
        $plans[] = $this->plan(['symbol' => 'S2', 'strategy' => 'Breakout + Volume', 'score' => 90]);

        // 4% stops are 25% of the account each, so cash would run out before the slots do
        config(['swing.portfolio.max_exposure_percent' => 1000]);
        $result = $this->record($plans);

        $this->assertSame([9, 5], [$result['logged'], $result['taken']]);
        // MA Trend first, then agreement — the score decides nothing
        $this->assertEqualsCanonicalizing(['S5', 'S6', 'S7', 'S8', 'S2'], SwingPaperTrade::where('portfolio', 'TAKEN')->pluck('symbol')->all());
        $this->assertSame(1, SwingPaperTrade::where('symbol', 'S2')->whereNotNull('portfolio')->count());
    }

    public function test_watch_only_plans_are_logged_but_never_take_a_slot(): void
    {
        $result = $this->record([
            $this->plan(['symbol' => 'PULL', 'strategy' => 'Trend Pullback']),
            $this->plan(['symbol' => 'MOMO', 'strategy' => 'Momentum Continuation']),
            $this->plan(['symbol' => 'BOTH', 'strategy' => 'Breakout + Volume']),
            $this->plan(['symbol' => 'BOTH', 'strategy' => 'MA Trend Following']),
        ]);

        $this->assertSame([4, 1], [$result['logged'], $result['taken']]);
        $this->assertSame(['BOTH' => 'MA Trend Following'], SwingPaperTrade::where('portfolio', 'TAKEN')->pluck('strategy', 'symbol')->all());
        $this->assertSame(0, SwingPaperTrade::whereIn('symbol', ['PULL', 'MOMO'])->whereNotNull('portfolio')->count());
    }

    public function test_watch_only_strategies_get_their_own_row_and_stay_out_of_the_trusted_total(): void
    {
        $this->record([
            $this->plan(['symbol' => 'MOMO', 'strategy' => 'Momentum Continuation']),
            $this->plan(['symbol' => 'TREND']),
        ]);

        $rows = collect(app(PaperTradeStatsService::class)->report()['rows'])->keyBy('name');

        $this->assertSame(1, $rows['Momentum Continuation (watch only)']['logged']);
        $this->assertSame(1, $rows['All signals (not watch-only)']['logged']);
    }

    public function test_scanning_twice_in_one_evening_does_not_take_more_than_the_limit(): void
    {
        config(['swing.portfolio.max_exposure_percent' => 1000]);
        $this->record(array_map(fn ($i) => $this->plan(['symbol' => "S{$i}"]), range(1, 4)));
        $this->record(array_map(fn ($i) => $this->plan(['symbol' => "S{$i}"]), range(1, 9)));

        $this->assertSame(5, SwingPaperTrade::held()->count());
        $this->assertSame(9, SwingPaperTrade::count());
    }

    public function test_random_entries_never_use_the_paper_accounts_slots(): void
    {
        $this->record([$this->plan(['strategy' => 'Random Control'])], kind: SwingPaperTrade::RANDOM);

        $this->assertNull(SwingPaperTrade::first()->portfolio);
        $this->assertSame(0, SwingPaperTrade::held()->count());
    }

    // -------------------------------------------------------------------- stats

    protected function finished(string $symbol, string $signal, ?string $exit, ?float $r, array $o = []): void
    {
        SwingPaperTrade::create($o + [
            'kind' => 'signal', 'symbol' => $symbol, 'strategy' => 'MA Trend Following', 'direction' => 'BUY', 'signal_date' => $signal, 'logged_at' => now(),
            'signal_price' => 100, 'max_entry' => 102, 'stop_price' => 92, 'stop_method' => 'atr', 'target1' => 116, 'rules' => [],
            'status' => $exit ? 'CLOSED' : 'OPEN', 'exit_date' => $exit, 'r_multiple' => $r, 'net_pnl' => $r === null ? null : $r * 1000,
        ]);
    }

    protected function statsRow(string $name): array
    {
        return collect(app(PaperTradeStatsService::class)->report()['rows'])->firstWhere('name', $name);
    }

    public function test_results_are_summarised_per_group(): void
    {
        $this->finished('A', '2026-11-02', '2026-11-05', 2.0);
        $this->finished('B', '2026-11-02', '2026-11-05', -1.0);
        $this->finished('C', '2026-11-03', null, null);
        $this->finished('D', '2026-11-02', '2026-11-05', -1.0, ['kind' => 'random', 'strategy' => 'Random Control']);

        $all = $this->statsRow('All signals (not watch-only)');
        $this->assertSame([2, 1, 50.0, 0.5, 2.0], [$all['closed'], $all['open'], $all['win_rate'], $all['average_r'], $all['profit_factor']]);
        $this->assertNull($all['range'], 'one week of trades is not evidence');
        $this->assertSame(1, $this->statsRow('Random entries (comparison)')['closed']);
        $this->assertSame(0, $this->statsRow('Paper account (within limits)')['logged']);
    }

    /** Five strategies on one move are one outcome. */
    public function test_the_paper_account_is_compared_with_holding_nifty_from_its_first_plan(): void
    {
        foreach (['2026-11-19' => 20000, '2026-11-20' => 21000, '2026-11-26' => 22000] as $date => $close) {
            \App\Models\Candle::create(['symbol' => '^NSEI', 'interval' => '1d', 'date' => $date, 'open' => $close, 'high' => $close,
                'low' => $close, 'close' => $close, 'volume' => 0, 'fetched_at' => now()]);
        }

        $this->assertNull(app(PaperTradeStatsService::class)->report()['benchmark'], 'nothing taken yet');

        $this->finished('A', '2026-11-20', '2026-11-25', 2.0, ['portfolio' => 'TAKEN', 'net_pnl' => 1500]);
        $this->finished('B', '2026-11-21', null, null, ['portfolio' => 'TAKEN', 'status' => 'OPEN']);
        $this->finished('C', '2026-11-20', '2026-11-25', 9.0, ['net_pnl' => 9000]);

        $this->assertSame(['since' => '2026-11-20', 'until' => '2026-11-26', 'account_percent' => 1.5, 'nifty_percent' => 4.76, 'closed' => 1],
            app(PaperTradeStatsService::class)->report()['benchmark']);
        $this->get('/swing/paper')->assertOk()->assertSee('NIFTYBEES')->assertSee('Behind the index');
    }

    public function test_overlapping_plans_on_one_stock_count_once(): void
    {
        $this->finished('A', '2026-11-02', '2026-11-10', 2.0);
        $this->finished('A', '2026-11-02', '2026-11-10', 1.8, ['strategy' => 'Breakout + Volume']);
        $this->finished('A', '2026-11-05', '2026-11-10', 1.5);
        $this->finished('A', '2026-11-10', '2026-11-12', 9.0);
        $this->finished('A', '2026-11-11', '2026-11-13', -1.0);

        $this->assertSame([2, 1.0], [$this->statsRow('All signals (not watch-only)')['closed'], $this->statsRow('All signals (not watch-only)')['total_r']]);
        $this->assertSame(1, $this->statsRow('Breakout + Volume (watch only)')['closed']);
    }

    public function test_an_order_that_never_filled_does_not_block_the_next(): void
    {
        $this->finished('A', '2026-11-02', null, null, ['status' => 'NO_ENTRY']);
        $this->finished('A', '2026-11-03', '2026-11-06', 1.0);

        $row = $this->statsRow('All signals (not watch-only)');
        $this->assertSame([1, 1], [$row['closed'], $row['not_entered']]);
    }

    public function test_late_plans_are_never_counted(): void
    {
        $this->finished('A', '2026-11-02', '2026-11-05', 5.0, ['late' => true]);

        $this->assertSame(0, $this->statsRow('All signals (not watch-only)')['closed']);
        $this->assertSame(1, app(PaperTradeStatsService::class)->report()['late']);
    }

    public function test_a_range_appears_once_there_are_enough_separate_weeks(): void
    {
        foreach (range(0, 9) as $week) {
            $signal = Carbon::parse('2026-06-01')->addWeeks($week);
            $this->finished("S{$week}", $signal->toDateString(), $signal->copy()->addDays(3)->toDateString(), $week % 2 ? 1.0 : -1.0);
        }

        $range = $this->statsRow('All signals (not watch-only)')['range'];
        $this->assertLessThan(0, $range[0]);
        $this->assertGreaterThan(0, $range[1]);
        $this->assertEqualsWithDelta(0, $range[0] + $range[1], 0.01);
    }

    public function test_the_paper_page_and_its_json_open_empty_and_with_trades(): void
    {
        $this->get('/swing/paper')->assertOk()->assertSee('Nothing logged yet');

        $this->finished('ACME', '2026-11-02', '2026-11-05', 2.0);
        $this->get('/swing/paper')->assertOk()->assertSee('ACME')->assertSee('Random entries');
        $this->getJson('/swing/paper')->assertOk()->assertJsonPath('rows.1.closed', 1);
    }

    // ------------------------------------------------------------------ the scan

    protected function rising(string $last, float $step): array
    {
        $dates = [];

        for ($day = Carbon::parse($last); count($dates) < 320; $day->subDay()) {
            $day->isWeekend() || $dates[] = $day->toDateString();
        }

        return array_map(fn ($date, $i) => $this->bar($date, 100 + $i * $step, 102 + $i * $step, 98 + $i * $step, 100 + $i * $step), array_reverse($dates), range(0, 319));
    }

    public function test_a_full_scan_logs_plans_and_random_entries(): void
    {
        config(['swing.paper.random_rate' => 1.0]);
        $this->bars = $this->rising(self::SIGNAL, 0.9);

        $result = app(SwingScannerService::class)->scan(['TESTCO'], paper: true);

        $this->assertGreaterThan(0, $result['paper']['signals']['logged']);
        $this->assertSame(1, $result['paper']['controls']['logged']);
        $this->assertSame(self::SIGNAL, SwingPaperTrade::first()->signal_date->toDateString());
        $this->assertSame(0, \App\Models\SwingSignal::where('strategy', 'Random Control')->count(), 'a random entry must never be shown as a signal');
        $this->assertSame(self::SIGNAL, \App\Models\SwingSignal::first()->signal_date->toDateString());
    }

    public function test_a_partial_scan_does_not_touch_the_log(): void
    {
        $this->bars = $this->rising(self::SIGNAL, 0.9);

        $this->assertNull(app(SwingScannerService::class)->scan(['TESTCO'])['paper']);
        $this->artisan('swing:scan', ['--symbols' => 'TESTCO'])->expectsOutputToContain('Paper log not updated')->assertSuccessful();
        $this->assertSame(0, SwingPaperTrade::count());
    }

    public function test_a_broken_log_does_not_lose_the_scan(): void
    {
        $this->bars = $this->rising(self::SIGNAL, 0.9);
        $this->mock(PaperTradeService::class)->shouldReceive('resolve')->andThrow(new \RuntimeException('disk full'));

        $result = app(SwingScannerService::class)->scan(['TESTCO'], paper: true);

        $this->assertSame('disk full', $result['paper']['error']);
        $this->assertGreaterThan(0, \App\Models\SwingSignal::count());
    }

    public function test_the_analysis_shows_the_limits_the_maximum_price_and_the_expiry(): void
    {
        $this->bars = $this->rising(self::SIGNAL, 0.9);
        $this->app->make(StockDataService::class)->shouldReceive('getIntradayCandles')->andReturn([]);

        $plan = collect($this->getJson('/swing/analyze?symbol=TESTCO')->assertOk()->json('strategies'))->firstWhere('state', 'READY')['plan'];

        $this->assertEqualsWithDelta($plan['entry'] * 1.02, $plan['max_entry'], 0.011);
        $this->assertLessThanOrEqual($plan['entry'] * 1.02, $plan['max_entry']);
        $this->assertGreaterThan($plan['risk_percent'], $plan['risk_percent_at_max_entry']);
        $this->assertSame(['ACTIVE', '2026-11-24'], [$plan['valid_for']['status'], $plan['valid_for']['session']]);
        $this->assertTrue($plan['portfolio']['take']);
        $this->assertGreaterThan(0, $plan['portfolio']['quantity']);
    }
}
