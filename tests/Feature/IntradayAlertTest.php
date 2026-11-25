<?php

namespace Tests\Feature;

use App\Models\IntradayAlert;
use App\Models\ScreenerResult;
use App\Models\StrategyStat;
use App\Services\IntradayAlertService;
use App\Services\StockDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntradayAlertTest extends TestCase
{
    use RefreshDatabase;

    protected array $candles = [];

    protected array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.token' => 'T', 'services.telegram.chat_id' => '42', 'screener.alerts.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->at('2026-11-25 10:30');

        $this->mock(StockDataService::class)->shouldReceive('getIntradayCandles')->andReturnUsing(function ($symbol) {
            $this->asked[] = $symbol;

            return $this->candles[$symbol] ?? $this->candles['*'] ?? null;
        });
        $this->candles['*'] = $this->rising();
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

    /** Quietly rising all morning, the last full bar traded on twice its usual volume. */
    protected function rising(string $until = '2026-11-25 10:25', int $lastVolume = 20000, float $step = 0.05): array
    {
        $end = Carbon::parse($until, 'Asia/Kolkata');
        $candles = [];

        for ($i = 59; $i >= 0; $i--) {
            $price = 500 + (59 - $i) * $step;
            $candles[] = ['timestamp' => $end->copy()->subMinutes($i * 5)->timestamp, 'open' => $price, 'high' => $price + 0.2, 'low' => $price - 0.2,
                'close' => $price, 'volume' => $i === 0 ? $lastVolume : 10000];
        }

        // Anything before 9:15 belongs to the day before
        return array_map(function ($c) use ($until) {
            $open = Carbon::parse(substr($until, 0, 10) . ' 09:15', 'Asia/Kolkata')->timestamp;

            return $c['timestamp'] < $open ? ['timestamp' => $c['timestamp'] - 18 * 3600] + $c : $c;
        }, $candles);
    }

    protected function found(string $symbol, array $strategies = ['High Momentum', 'Volume Breakout'], string $signal = 'BUY', string $date = '2026-11-25'): void
    {
        foreach ($strategies as $strategy) {
            ScreenerResult::create(['symbol' => $symbol, 'strategy' => $strategy, 'signal' => $signal, 'entry' => 500, 'stop_loss' => 490, 'target' => 520, 'volume_surge' => true, 'scan_date' => $date]);
        }
    }

    protected function stat(string $strategy, float $expectancy): void
    {
        StrategyStat::create(['strategy' => $strategy, 'trades' => 500, 'wins' => 300, 'win_rate' => 60, 'expectancy_r' => $expectancy]);
    }

    protected function watch(): array
    {
        return app(IntradayAlertService::class)->watch();
    }

    /** @return array<int, string> */
    protected function messages(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['text'])->all();
    }

    public function test_a_buyable_stock_is_sent_with_everything_needed(): void
    {
        $this->found('TCS');

        $this->assertSame(['TCS'], $this->watch()['sent']);

        $alert = IntradayAlert::first();
        $text = $this->messages()[0];
        $this->assertStringContainsString("🟢 INTRADAY BUY TCS x{$alert->quantity}", $text);
        $this->assertStringContainsString('Price ₹502.95 at 10:25', $text);
        $this->assertStringContainsString('Stop ₹' . number_format($alert->stop_loss, 2) . ' · Target ₹' . number_format($alert->target, 2), $text);
        $this->assertStringContainsString('Sell by 15:15', $text);
        $this->assertStringContainsString('High Momentum, Volume Breakout', $text);
        $this->assertLessThan($alert->entry, $alert->stop_loss);
        $this->assertGreaterThan($alert->entry, $alert->target);
    }

    public function test_the_highest_price_still_pays_one_and_a_half_times_the_risk(): void
    {
        $this->found('TCS');
        $this->watch();
        $a = IntradayAlert::first();

        $this->assertGreaterThan($a->entry, $a->max_entry);
        $this->assertGreaterThanOrEqual(1.5, ($a->target - $a->max_entry) / ($a->max_entry - $a->stop_loss));
        $this->assertLessThan(1.5, ($a->target - $a->max_entry - 0.02) / ($a->max_entry + 0.02 - $a->stop_loss));
    }

    public function test_the_money_at_risk_stays_inside_the_limit_per_trade(): void
    {
        $this->found('TCS');
        $this->watch();
        $a = IntradayAlert::first();

        $this->assertLessThanOrEqual(1000, $a->quantity * ($a->entry - $a->stop_loss));
        $this->assertLessThanOrEqual(100000, $a->quantity * $a->entry);
    }

    public function test_a_stock_is_announced_once_a_day(): void
    {
        $this->found('TCS');
        $this->watch();
        $this->assertSame([], $this->watch()['sent']);
        $this->assertCount(1, $this->messages());
    }

    public function test_no_more_than_the_daily_limit_is_sent(): void
    {
        foreach (range(1, 8) as $i) {
            $this->found("S{$i}");
        }

        $this->assertCount(5, $this->watch()['sent']);
        $this->assertStringContainsString('limit', $this->watch()['reason']);
        $this->assertCount(5, $this->messages());
    }

    public function test_the_strongest_agreement_goes_first(): void
    {
        config(['screener.alerts.max_per_day' => 1]);
        $this->found('WEAK');
        $this->found('STRONG', ['High Momentum', 'Volume Breakout', 'MACD Crossover']);

        $this->assertSame(['STRONG'], $this->watch()['sent']);
    }

    public function test_sells_lone_signals_disagreement_and_old_scans_are_ignored(): void
    {
        $this->found('SHORT', signal: 'SELL (short)');
        $this->found('LONELY', ['High Momentum']);
        $this->found('SPLIT');
        $this->found('SPLIT', ['RSI Reversal'], 'SELL (short)');
        $this->found('OLD', date: '2026-11-24');

        $result = $this->watch();
        $this->assertSame([], $result['sent']);
        $this->assertSame([], $this->asked, 'prices were downloaded for stocks that could never qualify');
    }

    /** Its entry was a price earlier in the day, so it cannot be a vote for buying now. */
    public function test_an_opening_range_breakout_is_not_a_vote(): void
    {
        $this->found('TCS', ['High Momentum', 'ORB + VWAP Breakout']);

        $this->assertSame([], $this->watch()['sent']);
    }

    public function test_a_stock_below_its_averages_is_not_sent(): void
    {
        $this->found('TCS');
        $this->candles['*'] = $this->rising(step: -0.05);

        $this->assertSame([], $this->watch()['sent']);
    }

    public function test_a_stock_without_volume_is_not_sent(): void
    {
        $this->found('TCS');
        $this->candles['*'] = $this->rising(lastVolume: 100);

        $this->assertSame([], $this->watch()['sent']);
    }

    /** The feed can lag; an old price is not one that can still be had. */
    public function test_an_old_price_is_not_sent(): void
    {
        $this->found('TCS');
        $this->candles['*'] = $this->rising('2026-11-25 10:05');

        $this->assertSame([], $this->watch()['sent']);
    }

    public function test_a_stock_whose_prices_cannot_be_read_is_skipped(): void
    {
        $this->found('DEAD', ['High Momentum', 'Volume Breakout', 'MACD Crossover']);
        $this->found('TCS');
        $this->candles['DEAD'] = [];

        $this->assertSame(['TCS'], $this->watch()['sent']);
    }

    public function test_nothing_is_sent_outside_the_alert_hours(): void
    {
        $this->found('TCS');

        foreach (['2026-11-25 09:29', '2026-11-25 15:00', '2026-11-28 10:30'] as $time) {
            $this->at($time);
            $this->assertSame([], $this->watch()['sent'], $time);
        }

        $this->assertSame([], $this->asked);
    }

    public function test_every_buy_message_carries_the_test_result(): void
    {
        $this->found('TCS');
        $this->watch();

        $this->assertStringContainsString('LOST MONEY IN TESTING', $this->messages()[0]);
        $this->assertFalse(IntradayAlert::first()->tested);
    }

    public function test_alerts_are_off_unless_asked_for(): void
    {
        $this->assertFalse((require config_path('screener.php'))['alerts']['enabled']);

        config(['screener.alerts.enabled' => false]);
        $this->found('TCS');

        $this->assertSame([], $this->watch()['sent']);
        $this->artisan('screener:watch')->assertSuccessful();
        $this->assertSame([], $this->asked);
    }

    public function test_turning_alerts_off_still_follows_a_trade_already_announced(): void
    {
        config(['screener.alerts.enabled' => false]);
        $this->alert();
        $this->bars([['10:30', 500, 521, 499, 520]]);

        $this->artisan('screener:watch')->expectsOutputToContain('Exit alert(s) sent: TCS');
    }

    public function test_after_a_backtest_only_strategies_that_passed_it_count(): void
    {
        $this->stat('High Momentum', 0.2);
        $this->stat('Volume Breakout', -0.2);
        $this->stat('MACD Crossover', 0.1);
        $this->found('FAILS');
        $this->found('PASSES', ['High Momentum', 'MACD Crossover']);

        $this->assertSame(['PASSES'], $this->watch()['sent']);
        $this->assertTrue(IntradayAlert::first()->tested);
    }

    public function test_an_undelivered_alert_is_tried_again(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true])]);
        $this->found('TCS');

        $this->assertSame([], $this->watch()['sent']);
        $this->assertSame(0, IntradayAlert::count());
        $this->assertSame(['TCS'], $this->watch()['sent']);
    }

    public function test_nothing_is_downloaded_until_telegram_is_set_up(): void
    {
        config(['services.telegram.token' => null]);
        $this->found('TCS');

        $this->assertSame([], $this->watch()['sent']);
        $this->assertSame([], $this->asked);
    }

    public function test_a_day_without_a_morning_scan_is_reported_once(): void
    {
        $this->artisan('screener:watch')->assertSuccessful();
        $this->artisan('screener:watch')->assertSuccessful();

        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('no results today', $this->messages()[0]);
    }

    public function test_the_command_sends_and_needs_the_token_from_the_web(): void
    {
        $this->found('TCS');
        $this->artisan('screener:watch')->expectsOutputToContain('1 alert(s) sent: TCS')->assertSuccessful();

        config(['screener.backtest_token' => 'right']);
        $this->getJson('/api/run-screener-watch?token=wrong')->assertForbidden();
        $this->getJson('/api/screener-watch-status')->assertOk();
    }

    // -------------------------------------------------------------------- exits

    protected function alert(array $o = []): IntradayAlert
    {
        $at = Carbon::parse('2026-11-25 10:25', 'Asia/Kolkata')->timestamp;

        return IntradayAlert::create($o + ['alert_date' => '2026-11-25', 'symbol' => 'TCS', 'price_time' => '10:25', 'entry' => 500, 'max_entry' => 502, 'stop_loss' => 490,
            'target' => 520, 'quantity' => 100, 'strategies' => ['High Momentum'], 'tested' => false, 'candle_at' => $at, 'seen_low' => 499, 'seen_high' => 501, 'status' => 'OPEN']);
    }

    /** @param array<int, array{0: string, 1: float, 2: float, 3: float, 4: float}> $bars time, open, high, low, close */
    protected function bars(array $bars): void
    {
        $this->candles['*'] = array_map(fn ($b) => ['timestamp' => Carbon::parse("2026-11-25 {$b[0]}", 'Asia/Kolkata')->timestamp,
            'open' => $b[1], 'high' => $b[2], 'low' => $b[3], 'close' => $b[4], 'volume' => 1000], $bars);
    }

    protected function follow(): IntradayAlert
    {
        app(IntradayAlertService::class)->follow();

        return IntradayAlert::first();
    }

    public function test_a_trade_still_between_its_levels_stays_open_and_silent(): void
    {
        $this->alert();
        $this->bars([['10:25', 499, 502, 498, 500], ['10:30', 500, 519, 491, 505]]);

        $this->assertSame('OPEN', $this->follow()->status);
        $this->assertSame([], $this->messages());
    }

    public function test_reaching_the_target_sends_one_exit(): void
    {
        $this->alert();
        $this->bars([['10:25', 499, 502, 498, 500], ['10:30', 500, 510, 499, 509], ['10:35', 509, 521, 508, 515]]);

        $closed = $this->follow();
        $this->follow();

        $this->assertSame(['TARGET', '10:35', 520.0], [$closed->status, $closed->exit_time, $closed->exit_price]);
        $this->assertGreaterThan(1.7, $closed->r_multiple);
        $this->assertLessThan(2.0, $closed->r_multiple);
        $this->assertCount(1, $this->messages());
        $this->assertStringContainsString('🟢 EXIT TCS — target reached', $this->messages()[0]);
        $this->assertStringContainsString('Result if taken: +₹', $this->messages()[0]);
    }

    public function test_reaching_the_stop_sends_an_exit(): void
    {
        $this->alert();
        $this->bars([['10:30', 500, 501, 489, 495]]);

        $closed = $this->follow();
        $this->assertSame('STOP', $closed->status);
        $this->assertEqualsWithDelta(490 * 0.9995, $closed->exit_price, 0.01);
        $this->assertLessThan(-1.0, $closed->r_multiple);
        $this->assertStringContainsString('🔴 EXIT TCS — stop reached', $this->messages()[0]);
        $this->assertStringContainsString('−₹', $this->messages()[0]);
    }

    public function test_a_candle_reaching_both_levels_counts_as_the_stop(): void
    {
        $this->alert();
        $this->bars([['10:30', 500, 525, 485, 510]]);

        $this->assertSame('STOP', $this->follow()->status);
    }

    public function test_opening_below_the_stop_gets_the_worse_price(): void
    {
        $this->alert();
        $this->bars([['10:30', 480, 482, 478, 481]]);

        $this->assertEqualsWithDelta(480 * 0.9995, $this->follow()->exit_price, 0.01);
    }

    /** What the alert's own candle did before the alert cannot have closed the trade. */
    public function test_levels_touched_before_the_alert_do_not_count(): void
    {
        $this->alert(['seen_low' => 489, 'seen_high' => 521]);
        $this->bars([['10:20', 480, 530, 470, 500], ['10:25', 499, 521, 489, 500]]);
        $this->assertSame('OPEN', $this->follow()->status);

        $this->bars([['10:25', 499, 521, 488, 500]]);
        $this->assertSame('STOP', $this->follow()->status);
    }

    public function test_the_trade_is_closed_at_the_square_off_time(): void
    {
        $this->alert();
        $this->bars([['10:30', 500, 505, 499, 504], ['15:10', 504, 507, 503, 506], ['15:15', 506, 530, 480, 500]]);

        $this->at('2026-11-25 15:14');
        $this->assertSame('OPEN', $this->follow()->status);

        $this->at('2026-11-25 15:15');
        $closed = $this->follow();
        $this->assertSame(['TIME', '15:15'], [$closed->status, $closed->exit_time]);
        $this->assertEqualsWithDelta(506 * 0.9995, $closed->exit_price, 0.01);
        $this->assertStringContainsString('⏰ EXIT TCS — 15:15', $this->messages()[0]);
    }

    public function test_an_exit_that_could_not_be_delivered_is_sent_next_time(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true])]);
        $this->alert();
        $this->bars([['10:30', 500, 521, 499, 520]]);

        $this->assertFalse($this->follow()->exit_alerted);
        $this->assertTrue($this->follow()->exit_alerted);
    }

    /** Entries stop at 15:00, but a trade already open has to be followed to its end. */
    public function test_the_command_follows_open_trades_after_entries_have_stopped(): void
    {
        $this->alert();
        $this->bars([['15:05', 500, 521, 499, 520]]);
        $this->at('2026-11-25 15:10');

        $this->artisan('screener:watch')->expectsOutputToContain('Exit alert(s) sent: TCS')->assertSuccessful();
    }

    public function test_the_buy_message_says_when_not_to_buy(): void
    {
        $this->found('TCS');
        $this->watch();

        $this->assertStringContainsString('Do not buy', $this->messages()[0]);
    }

    public function test_a_weekend_is_never_open(): void
    {
        $this->at('2026-11-27 10:30');
        $this->assertTrue(app(IntradayAlertService::class)->isOpen());

        $this->at('2026-11-28 10:30');
        $this->assertFalse(app(IntradayAlertService::class)->isOpen());
    }

    /** Two runs looking at the same stock at once must not both announce it. */
    public function test_an_alert_another_run_has_just_claimed_is_not_sent_again(): void
    {
        $this->found('TCS');
        $this->mock(StockDataService::class)->shouldReceive('getIntradayCandles')->andReturnUsing(function () {
            $this->alert();

            return $this->rising();
        });

        $this->assertSame([], $this->watch()['sent']);
        $this->assertSame([], $this->messages());
        $this->assertSame(1, IntradayAlert::count());
    }
}
