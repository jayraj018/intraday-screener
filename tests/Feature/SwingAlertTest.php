<?php

namespace Tests\Feature;

use App\Console\Commands\RunSwingScan;
use App\Models\SwingPaperTrade;
use App\Models\SwingSignal;
use App\Services\ScreenerService;
use App\Services\Swing\SwingAlertService;
use App\Services\Swing\SwingScannerService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SwingAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.token' => 'SECRET-TOKEN', 'services.telegram.chat_id' => '42']);
        Http::preventStrayRequests();
        $this->at('2026-11-25 16:20');
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

    protected function telegram(int $status = 200): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => $status === 200, 'description' => 'nope'], $status)]);
    }

    /** @return array<int, string> */
    protected function messages(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['text'])->all();
    }

    protected function trade(string $symbol, array $o = []): SwingPaperTrade
    {
        return SwingPaperTrade::create($o + [
            'kind' => 'signal', 'symbol' => $symbol, 'strategy' => 'MA Trend Following', 'direction' => 'BUY', 'signal_date' => '2026-11-25', 'logged_at' => now(),
            'signal_price' => 498, 'entry_trigger' => 500, 'max_entry' => 510, 'stop_price' => 480, 'stop_method' => 'atr', 'target1' => 540,
            'rules' => ['swing.exits' => ['max_holding_days' => 10]], 'portfolio' => 'TAKEN', 'status' => 'PENDING',
        ]);
    }

    protected function scanResult(array $o = []): array
    {
        return $o + ['scanned' => 490, 'stale' => 3, 'stored' => 300, 'states' => ['READY' => 120],
            'paper' => ['signals' => ['logged' => 1, 'late' => 0, 'taken' => 1], 'controls' => ['logged' => 20], 'resolved' => []]];
    }

    protected function finish(array $o = []): bool
    {
        return app(SwingAlertService::class)->scanFinished($this->scanResult($o));
    }

    protected function closed(string $symbol, string $reason, float $r): SwingPaperTrade
    {
        return $this->trade($symbol, ['status' => 'CLOSED', 'alerted_status' => 'OPEN', 'entry_price' => 500, 'exit_price' => 540,
            'exit_date' => '2026-11-25', 'exit_reason' => $reason, 'r_multiple' => $r, 'net_pnl' => $r * 1000, 'bars_held' => 3]);
    }

    // ----------------------------------------------------------------- telegram

    public function test_nothing_is_sent_until_telegram_is_set_up(): void
    {
        config(['services.telegram.token' => null]);
        Http::fake();

        $this->assertFalse(app(TelegramService::class)->send('hello'));
        $this->artisan('telegram:test')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_a_message_goes_to_the_configured_chat(): void
    {
        $this->telegram();

        $this->assertTrue(app(TelegramService::class)->send('hello'));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/botSECRET-TOKEN/sendMessage' && $r['chat_id'] === '42' && $r['text'] === 'hello');
        $this->artisan('telegram:test')->assertSuccessful();
    }

    public function test_a_long_message_is_split_between_lines(): void
    {
        $this->telegram();
        $lines = array_map(fn ($i) => "line {$i} " . str_repeat('x', 80), range(1, 120));

        $this->assertTrue(app(TelegramService::class)->send(implode("\n", $lines)));
        $this->assertGreaterThan(1, count($this->messages()));
        $this->assertSame($lines, explode("\n", implode("\n", $this->messages())));
        $this->assertLessThanOrEqual(4096, max(array_map('mb_strlen', $this->messages())));
    }

    /** The failed URL contains the token, so nothing from the exception may reach the log. */
    public function test_a_failure_never_throws_and_never_logs_the_token(): void
    {
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message . json_encode($m->context);
        });
        Http::fake(fn () => throw new ConnectionException('cURL error for https://api.telegram.org/botSECRET-TOKEN/sendMessage'));

        $this->assertFalse(app(TelegramService::class)->send('hello'));
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('SECRET-TOKEN', implode($logged));
    }

    // ------------------------------------------------------------------- digest

    public function test_an_exit_says_why_and_how_much(): void
    {
        $this->telegram();
        $this->closed('TCS', 'target1', 1.9);
        $this->closed('INFY', 'stop_gap', -1.2);
        $this->finish();

        $text = $this->messages()[0];
        $this->assertStringContainsString('🟢 EXIT TCS — target hit at ₹540.00 on 25 Nov, +1.90R (+₹1,900.00 after charges)', $text);
        $this->assertStringContainsString('🔴 EXIT INFY — opened below stop', $text);
        $this->assertStringContainsString('-1.20R (−₹1,200.00 after charges)', $text);
        $this->assertStringContainsString('no money was traded', $text);
    }

    public function test_a_plan_says_everything_needed_to_act_on_it(): void
    {
        $this->telegram();
        $this->trade('TCS');
        $this->finish();

        $text = $this->messages()[0];
        $this->assertStringContainsString('🆕 PLAN TCS x50 — buy above ₹500.00, not above ₹510.00, stop ₹480.00, target ₹540.00', $text);
        $this->assertStringContainsString('Valid Thu, 26 Nov 2026 only', $text);
        $this->assertStringContainsString('490 stocks scanned, 120 READY plans', $text);
    }

    public function test_entries_and_orders_that_did_not_fill_are_reported(): void
    {
        $this->telegram();
        $this->trade('SBIN', ['status' => 'OPEN', 'alerted_status' => 'PENDING', 'entry_price' => 500.25, 'bars_held' => 1]);
        $this->trade('ITC', ['status' => 'NO_ENTRY', 'alerted_status' => 'PENDING', 'exit_reason' => 'not_triggered']);
        $this->finish();

        $this->assertStringContainsString('✅ ENTERED SBIN at ₹500.25 — stop ₹480.00, target ₹540.00', $this->messages()[0]);
        $this->assertStringContainsString('➖ NOT ENTERED ITC — price did not reach the entry', $this->messages()[0]);
        $this->assertStringContainsString('Holding 1 now', $this->messages()[0]);
    }

    /** The evening before, while the closing sale can still be made. */
    public function test_the_holding_limit_is_announced_one_session_before(): void
    {
        $this->telegram();
        $this->trade('SBIN', ['status' => 'OPEN', 'alerted_status' => 'OPEN', 'bars_held' => 9]);
        $this->trade('ITC', ['status' => 'OPEN', 'alerted_status' => 'OPEN', 'bars_held' => 8]);
        $this->finish();

        $this->assertStringContainsString('⏰ LAST DAY SBIN', $this->messages()[0]);
        $this->assertStringNotContainsString('LAST DAY ITC', $this->messages()[0]);
    }

    public function test_each_change_is_reported_once(): void
    {
        $this->telegram();
        $this->closed('TCS', 'target1', 1.9);
        $this->finish();
        $this->finish();

        $this->assertStringContainsString('EXIT TCS', $this->messages()[0]);
        $this->assertStringNotContainsString('EXIT TCS', $this->messages()[1]);
        $this->assertStringContainsString('Nothing changed', $this->messages()[1]);
    }

    public function test_an_alert_that_could_not_be_delivered_is_sent_by_the_next_scan(): void
    {
        $trade = $this->closed('TCS', 'stop', -1.0);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true])]);

        $this->assertFalse($this->finish());
        $this->assertSame('OPEN', $trade->fresh()->alerted_status);

        $this->assertTrue($this->finish());
        $this->assertStringContainsString('EXIT TCS', $this->messages()[1]);
        $this->assertSame('CLOSED', $trade->fresh()->alerted_status);
    }

    /** Otherwise the first message after setting it up would be months of old news. */
    public function test_setting_telegram_up_later_does_not_send_a_backlog(): void
    {
        config(['services.telegram.token' => null]);
        $this->closed('TCS', 'stop', -1.0);
        $this->finish();

        config(['services.telegram.token' => 'SECRET-TOKEN']);
        $this->telegram();
        $this->finish();

        $this->assertStringNotContainsString('EXIT TCS', $this->messages()[0]);
    }

    public function test_plans_outside_the_paper_account_are_not_announced(): void
    {
        $this->telegram();
        $this->trade('SKIPPED1', ['portfolio' => 'SKIPPED']);
        $this->trade('RANDOM1', ['kind' => 'random', 'portfolio' => null]);
        $this->finish();

        $this->assertStringNotContainsString('SKIPPED1', $this->messages()[0]);
        $this->assertStringNotContainsString('RANDOM1', $this->messages()[0]);
    }

    public function test_a_scan_that_half_worked_carries_a_warning(): void
    {
        $this->telegram();
        $this->finish(['stale' => 200, 'paper' => ['error' => 'SQLSTATE secret query']]);
        $this->finish(['paper' => ['signals' => ['late' => 7]]]);

        $this->assertStringContainsString('200 stocks skipped', $this->messages()[0]);
        $this->assertStringContainsString('paper log could not be written', $this->messages()[0]);
        $this->assertStringNotContainsString('secret query', $this->messages()[0]);
        $this->assertStringContainsString('7 plans logged after the market opened', $this->messages()[1]);
        $this->assertStringNotContainsString('stocks skipped', $this->messages()[1]);
    }

    // ------------------------------------------------------------- the scan command

    protected function scanner(callable $scan): void
    {
        $this->mock(ScreenerService::class)->shouldReceive('watchlist')->andReturn(['TESTCO']);
        $mock = $this->mock(SwingScannerService::class);
        $mock->shouldReceive('strategies')->andReturn([]);
        $mock->shouldReceive('scan')->andReturnUsing($scan);
    }

    public function test_the_daily_scan_announces_itself(): void
    {
        $this->telegram();
        $this->scanner(fn () => $this->scanResult(['paper' => ['signals' => ['logged' => 1, 'late' => 0, 'taken' => 1], 'controls' => ['logged' => 2], 'resolved' => ['closed' => 0, 'opened' => 0, 'no_entry' => 0]]]));

        $this->artisan('swing:scan')->assertSuccessful();
        $this->assertStringContainsString('📊 Swing scan', $this->messages()[0]);
    }

    public function test_a_scan_of_chosen_stocks_stays_silent(): void
    {
        $this->telegram();
        $this->scanner(fn () => $this->scanResult(['paper' => null]));

        $this->artisan('swing:scan', ['--symbols' => 'TESTCO'])->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_failed_scan_is_reported_with_its_reason(): void
    {
        $this->telegram();
        $this->scanner(fn () => throw new \RuntimeException('The NIFTY benchmark could not be refreshed'));

        $thrown = null;

        try {
            $this->artisan('swing:scan')->run();
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertSame('The NIFTY benchmark could not be refreshed', $thrown, 'the failure was swallowed');

        $this->assertStringContainsString('🚨 Swing scan FAILED', $this->messages()[0]);
        $this->assertStringContainsString('NIFTY benchmark', $this->messages()[0]);
    }

    public function test_an_unexpected_error_does_not_leak_its_details(): void
    {
        $this->telegram();
        $this->scanner(fn () => throw new \LogicException('host=db.internal password=hunter2'));

        try {
            $this->artisan('swing:scan')->run();
        } catch (\LogicException) {
        }

        $this->assertStringContainsString('LogicException', $this->messages()[0]);
        $this->assertStringNotContainsString('hunter2', $this->messages()[0]);
    }

    public function test_a_scan_that_found_no_prices_is_reported(): void
    {
        $this->telegram();
        $this->scanner(fn () => $this->scanResult(['scanned' => 0, 'stale' => 490]));

        $this->artisan('swing:scan')->assertFailed();
        $this->assertStringContainsString('FAILED', $this->messages()[0]);
    }

    public function test_telegram_being_down_does_not_fail_the_scan(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->scanner(fn () => $this->scanResult(['paper' => null]));

        $this->artisan('swing:scan')->assertSuccessful();
    }

    // ------------------------------------------------------------ the scan that never ran

    protected function signalStored(string $istTime): void
    {
        $this->at($istTime);
        SwingSignal::create(['scan_date' => now('Asia/Kolkata')->toDateString(), 'symbol' => 'TESTCO', 'strategy' => 'MA Trend Following', 'state' => 'WATCHLIST', 'signal_price' => 1]);
    }

    public function test_a_missing_scan_is_reported(): void
    {
        $this->telegram();
        $this->at('2026-11-25 17:00');

        $this->artisan('swing:check')->assertSuccessful();
        $this->assertStringContainsString('has not run since the market closed', $this->messages()[0]);
    }

    /** The morning scan read yesterday's close, so it does not count as today's. */
    public function test_a_scan_from_before_the_close_does_not_count(): void
    {
        $this->telegram();
        $this->signalStored('2026-11-25 09:28');
        $this->at('2026-11-25 17:00');

        $this->artisan('swing:check');
        $this->assertCount(1, $this->messages());
    }

    public function test_all_is_quiet_when_the_scan_ran_is_running_or_is_not_due(): void
    {
        $this->telegram();

        $this->at('2026-11-25 15:59');
        $this->artisan('swing:check');

        $this->at('2026-11-28 17:00');   // Saturday
        $this->artisan('swing:check');

        $this->at('2026-11-25 17:00');
        Cache::put(RunSwingScan::STATUS_CACHE_KEY, ['state' => 'running', 'started_at' => now()->subHour()->toIso8601String(), 'heartbeat_at' => now()->subMinutes(2)->toIso8601String()]);
        $this->artisan('swing:check');
        Cache::forget(RunSwingScan::STATUS_CACHE_KEY);

        $this->signalStored('2026-11-25 16:25');
        $this->at('2026-11-25 17:00');
        $this->artisan('swing:check');

        Http::assertNothingSent();
    }

    public function test_a_scan_killed_part_way_is_reported(): void
    {
        $this->telegram();
        $this->at('2026-11-25 17:00');
        Cache::put(RunSwingScan::STATUS_CACHE_KEY, ['state' => 'running', 'started_at' => now()->subHour()->toIso8601String(), 'heartbeat_at' => now()->subMinutes(30)->toIso8601String()]);

        $this->artisan('swing:check');
        $this->assertStringContainsString('stopped part way', $this->messages()[0]);
    }

    public function test_the_check_can_only_be_started_with_the_token(): void
    {
        config(['screener.backtest_token' => 'right']);

        $this->getJson('/api/run-swing-check?token=wrong')->assertForbidden();
        $this->getJson('/api/swing-check-status')->assertOk()->assertJsonPath('run.state', 'never_run');
    }
}
