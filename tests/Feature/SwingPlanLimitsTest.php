<?php

namespace Tests\Feature;

use App\Models\Candle;
use App\Models\SwingPaperTrade;
use App\Services\Swing\PlanValidityService;
use App\Services\Swing\PortfolioLimitService;
use App\Services\Swing\SectorService;
use App\Services\Swing\SwingBacktestService;
use App\Services\Swing\SwingSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwingPlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function at(string $istTime): void
    {
        Carbon::setTestNow(Carbon::parse($istTime, 'Asia/Kolkata')->utc());
    }

    protected function planFor(float $trigger = 500.0, string $direction = 'BUY', ?float $signalPrice = null): SwingSetup
    {
        return new SwingSetup('TESTCO', 'MA Trend Following', $direction, '2026-11-23', $signalPrice ?? $trigger, $direction === 'BUY' ? $trigger - 20 : $trigger + 20,
            'atr', $direction === 'BUY' ? $trigger + 40 : $trigger - 40, entryTrigger: $trigger);
    }

    // ------------------------------------------------------------- max entry

    public function test_the_maximum_entry_is_the_configured_gap_past_the_trigger(): void
    {
        $this->assertSame(510.0, $this->planFor(500)->maxEntry());
        $this->assertSame(490.0, $this->planFor(500, 'SELL')->maxEntry());

        // 333.33 * 1.02 = 339.9966: rounded up it would be a price the backtest refuses
        $this->assertSame(339.99, $this->planFor(333.33)->maxEntry());
    }

    /** The price on the plan and the price the backtest refuses must be the same number. */
    public function test_the_backtest_fills_at_the_maximum_entry_and_refuses_just_above_it(): void
    {
        foreach ([500.0, 333.33, 87.15, 2417.6, 19.95] as $trigger) {
            $setup = $this->planFor($trigger);
            $fill = fn (float $open) => app(SwingBacktestService::class)->enter($setup, [
                ['date' => '2026-11-23', 'open' => $trigger, 'high' => $trigger, 'low' => $trigger, 'close' => $trigger],
                ['date' => '2026-11-24', 'open' => $open, 'high' => $open, 'low' => $open, 'close' => $open],
            ], 0);

            $this->assertIsArray($fill($setup->maxEntry()), "refused at {$setup->maxEntry()}");
            $this->assertSame('gapped_too_far', $fill($setup->maxEntry() + 0.01));
        }
    }

    // ------------------------------------------------------------- one session

    public function test_a_trigger_reached_on_the_second_session_is_too_late(): void
    {
        $setup = $this->planFor(500, signalPrice: 495);
        $bar = fn ($day, $high) => ['date' => "2026-11-{$day}", 'open' => 490, 'high' => $high, 'low' => 488, 'close' => 490];
        $walker = app(SwingBacktestService::class);

        $this->assertSame('not_triggered', $walker->enter($setup, [$bar(23, 495), $bar(24, 499), $bar(25, 505)], 0));
        $this->assertIsArray($walker->enter($setup, [$bar(23, 495), $bar(24, 505)], 0));
    }

    public function test_a_plan_is_active_until_its_session_closes(): void
    {
        $service = app(PlanValidityService::class);

        $this->at('2026-11-23 16:30');
        $evening = $service->forSignal('2026-11-23', ['2026-11-23']);
        $this->assertSame(['ACTIVE', '2026-11-24', false], [$evening['status'], $evening['session'], $evening['confirmed']]);

        $this->at('2026-11-24 15:29');
        $this->assertSame('ACTIVE', $service->forSignal('2026-11-23', ['2026-11-23', '2026-11-24'])['status']);

        $this->at('2026-11-24 15:31');
        $this->assertSame('EXPIRED', $service->forSignal('2026-11-23', ['2026-11-23', '2026-11-24'])['status']);
    }

    public function test_a_friday_plan_is_for_monday(): void
    {
        $this->at('2026-11-21 10:00');

        $this->assertSame('2026-11-23', app(PlanValidityService::class)->forSignal('2026-11-20', ['2026-11-20'])['session']);
    }

    /** A weekday without a bar may be a holiday; the plan then belongs to the next session. */
    public function test_a_holiday_does_not_use_up_the_plan(): void
    {
        $service = app(PlanValidityService::class);

        $this->at('2026-11-25 08:00');
        $this->assertSame('UNCONFIRMED', $service->forSignal('2026-11-23', ['2026-11-23'])['status']);

        $this->at('2026-11-25 10:00');
        $known = $service->forSignal('2026-11-23', ['2026-11-23', '2026-11-25']);
        $this->assertSame(['ACTIVE', '2026-11-25'], [$known['status'], $known['session']]);
    }

    // -------------------------------------------------------- portfolio limits

    /** @return array<int, array{symbol: string, entry: float, stop: float}> */
    protected function plans(int $count, float $entry = 100, float $stop = 92): array
    {
        return array_map(fn ($i) => ['symbol' => "S{$i}", 'entry' => $entry, 'stop' => $stop], range(1, $count));
    }

    protected function held(string $symbol, float $value = 12500, float $risk = 1000, string $status = 'OPEN', ?string $portfolio = 'TAKEN'): void
    {
        SwingPaperTrade::create([
            'kind' => 'signal', 'symbol' => $symbol, 'strategy' => 'MA Trend Following', 'direction' => 'BUY', 'signal_date' => '2026-11-20',
            'logged_at' => now(), 'signal_price' => 100, 'max_entry' => 102, 'stop_price' => 92, 'stop_method' => 'atr', 'target1' => 116,
            'rules' => [], 'portfolio' => $portfolio, 'planned_value' => $value, 'planned_risk' => $risk, 'status' => $status,
        ]);
    }

    protected function taken(array $decisions): array
    {
        return array_keys(array_filter($decisions, fn ($d) => $d['take']));
    }

    public function test_only_the_best_five_are_taken(): void
    {
        $decisions = app(PortfolioLimitService::class)->decide($this->plans(8));

        $this->assertSame(['S1', 'S2', 'S3', 'S4', 'S5'], $this->taken($decisions));
        $this->assertStringContainsString('All 5 positions', $decisions['S6']['reason']);
    }

    public function test_open_and_waiting_positions_use_up_slots_but_finished_ones_do_not(): void
    {
        $this->held('OPEN1');
        $this->held('WAIT1', status: 'PENDING');
        $this->held('DONE1', status: 'CLOSED');
        $this->held('SKIP1', portfolio: 'SKIPPED');

        $this->assertCount(3, $this->taken(app(PortfolioLimitService::class)->decide($this->plans(8))));
    }

    public function test_total_open_risk_is_capped(): void
    {
        config(['swing.costs.risk_per_trade_percent' => 2.0, 'swing.portfolio.max_open_risk_percent' => 6.0]);

        $decisions = app(PortfolioLimitService::class)->decide($this->plans(5));

        $this->assertCount(3, $this->taken($decisions));
        $this->assertStringContainsString('8.0%, above the 6.0% limit', $decisions['S4']['reason']);
    }

    /** 1% risk on a 2.5% stop is 40% of the account, so a third position has no money left. */
    public function test_positions_cannot_be_worth_more_than_the_capital(): void
    {
        $decisions = app(PortfolioLimitService::class)->decide($this->plans(5, 100, 97.5));

        $this->assertSame(['S1', 'S2'], $this->taken($decisions));
        $this->assertStringContainsString('only ₹20,000 is free', $decisions['S3']['reason']);
    }

    public function test_a_stock_already_held_is_not_taken_again(): void
    {
        $this->held('S1');

        $decisions = app(PortfolioLimitService::class)->decide($this->plans(1));

        $this->assertFalse($decisions['S1']['take']);
        $this->assertStringContainsString('Already holding', $decisions['S1']['reason']);
    }

    /** A smaller position further down the list may still fit where a bigger one did not. */
    public function test_a_plan_that_does_not_fit_does_not_block_the_next(): void
    {
        $decisions = app(PortfolioLimitService::class)->decide([
            ['symbol' => 'BIG1', 'entry' => 100, 'stop' => 98.5],
            ['symbol' => 'BIG2', 'entry' => 100, 'stop' => 98.5],
            ['symbol' => 'SMALL', 'entry' => 100, 'stop' => 90],
        ]);

        $this->assertSame(['BIG1', 'SMALL'], $this->taken($decisions));
    }

    public function test_no_more_than_two_stocks_from_one_sector_held_or_new(): void
    {
        \Illuminate\Support\Facades\Cache::forever(SectorService::CACHE_KEY, [
            'S1' => 'Financial Services', 'S2' => 'Financial Services', 'S3' => 'Financial Services', 'S4' => 'Capital Goods',
            'BANK' => 'Financial Services',
        ]);

        $this->assertSame(['S1', 'S2', 'S4', 'S5'], $this->taken(app(PortfolioLimitService::class)->decide($this->plans(5))));

        $this->held('BANK');
        $decisions = app(PortfolioLimitService::class)->decide($this->plans(5));

        // S5 has no known sector, so nothing limits it
        $this->assertSame(['S1', 'S4', 'S5'], $this->taken($decisions));
        $this->assertStringContainsString('Already 2 in Financial Services', $decisions['S2']['reason']);
    }

    public function test_sectors_are_read_from_the_nse_index_list_and_a_failed_download_keeps_the_last_ones(): void
    {
        \Illuminate\Support\Facades\Http::fake(['nsearchives.nseindia.com/*' => \Illuminate\Support\Facades\Http::sequence()
            ->push("Company Name,Industry,Symbol,Series,ISIN Code\n\"HDFC Bank Ltd.\",Financial Services,HDFCBANK,EQ,INE040A01034\nABB India Ltd.,Capital Goods,ABB,EQ,INE117A01022\n")
            ->push('', 500)]);

        $sectors = app(SectorService::class);

        $this->assertSame(2, $sectors->refresh());
        $this->assertSame('Financial Services', $sectors->of('hdfcbank'));

        \Illuminate\Support\Facades\Cache::forget('nse.industry.' . config('screener.universe_index'));
        \Illuminate\Support\Facades\Cache::forget('nse.industry.' . config('screener.universe_index') . '.last_good');
        $this->assertSame(2, $sectors->refresh());
        $this->assertSame('Capital Goods', $sectors->of('ABB'));
    }

    // --------------------------------------------------------------- dashboard

    public function test_the_dashboard_shows_the_limit_the_maximum_price_and_the_expiry(): void
    {
        $this->at('2026-11-23 17:00');
        Candle::create(['symbol' => '^NSEI', 'interval' => '1d', 'date' => '2026-11-23', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1, 'fetched_at' => now()]);

        \App\Models\SwingSignal::create([
            'scan_date' => '2026-11-23', 'signal_date' => '2026-11-23', 'symbol' => 'TESTCO', 'strategy' => 'MA Trend Following', 'state' => 'READY',
            'direction' => 'BUY', 'score' => 70, 'signal_price' => 498, 'entry_trigger' => 500, 'stop_price' => 480, 'stop_method' => 'atr',
            'target1' => 540, 'target2' => 580, 'target1_r' => 2, 'target2_r' => 4, 'checks' => [],
        ]);

        $this->get('/swing')->assertOk()
            ->assertSee('Do not pay above')->assertSee('₹510.00')
            ->assertSee('Valid Tue, 24 Nov 2026 only')
            ->assertSee('Fits limits');
    }

    /** Rows stored before this release have no signal date, and the page must still open. */
    public function test_the_dashboard_still_opens_with_signals_from_before_the_column_existed(): void
    {
        \App\Models\SwingSignal::create([
            'scan_date' => '2026-11-23', 'symbol' => 'TESTCO', 'strategy' => 'MA Trend Following', 'state' => 'READY', 'direction' => 'BUY', 'score' => 70,
            'signal_price' => 498, 'entry_trigger' => 500, 'stop_price' => 480, 'stop_method' => 'atr', 'target1' => 540, 'target2' => 580, 'target1_r' => 2, 'target2_r' => 4, 'checks' => [],
        ]);

        $this->get('/swing')->assertOk()->assertSee('TESTCO');
    }
}
