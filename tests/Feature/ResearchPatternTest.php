<?php

namespace Tests\Feature;

use App\Models\NseDailyBar;
use App\Models\ResearchSignal;
use App\Services\Research\GapHoldDeliveryDetector;
use App\Services\Research\NseBhavcopyService;
use App\Services\Research\ResearchSignalTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchPatternTest extends TestCase
{
    use RefreshDatabase;

    /** 40 quiet sessions for one symbol, then a configurable last bar. */
    protected function seedBars(string $symbol, array $last, int $n = 40): array
    {
        $dates = [];
        $d = Carbon::parse('2026-09-01');

        while (count($dates) < $n + 25) {
            if (! $d->isWeekend()) {
                $dates[] = $d->toDateString();
            }
            $d->addDay();
        }

        for ($i = 0; $i < $n - 1; $i++) {
            NseDailyBar::create(['date' => $dates[$i], 'symbol' => $symbol, 'series' => 'EQ', 'prev_close' => 100,
                'open' => 100, 'high' => 101.5, 'low' => 98.5, 'close' => 100, 'volume' => 1_000_000, 'turnover' => 1e8,
                'deliv_qty' => 400_000, 'deliv_pct' => 40]);
        }

        NseDailyBar::create(array_merge(['date' => $dates[$n - 1], 'symbol' => $symbol, 'series' => 'EQ', 'prev_close' => 100,
            'open' => 103, 'high' => 106, 'low' => 102.5, 'close' => 105.5, 'volume' => 3_000_000, 'turnover' => 3.1e8,
            'deliv_qty' => 1_500_000, 'deliv_pct' => 50], $last));

        return $dates;
    }

    public function test_detects_the_pattern_with_every_condition_explained(): void
    {
        $dates = $this->seedBars('ABC', []);

        $scan = app(GapHoldDeliveryDetector::class)->scan();

        $this->assertSame($dates[39], $scan['date']);
        $this->assertCount(1, $scan['matches']);
        $m = $scan['matches'][0];
        $this->assertTrue(collect($m['conditions'])->every(fn ($c) => $c['pass']));
        $this->assertCount(5, $m['conditions']);
        $this->assertLessThan(105.5, $m['plan']['stop']);
        $this->assertEqualsWithDelta(105.5 + 2 * (105.5 - $m['plan']['stop']), $m['plan']['reference_target'], 0.02);
    }

    public function test_low_delivery_makes_it_a_near_miss_not_a_match(): void
    {
        $this->seedBars('ABC', ['deliv_pct' => 25]);

        $scan = app(GapHoldDeliveryDetector::class)->scan();

        $this->assertCount(0, $scan['matches']);
        $this->assertCount(1, $scan['near_misses']);
        $failed = collect($scan['near_misses'][0]['conditions'])->where('pass', false)->pluck('label')->all();
        $this->assertSame(['Delivery % >= its 20-day average'], $failed);
    }

    public function test_a_gap_that_faded_is_not_a_match(): void
    {
        $this->seedBars('ABC', ['close' => 102.8, 'low' => 101]);

        $this->assertCount(0, app(GapHoldDeliveryDetector::class)->scan()['matches']);
    }

    public function test_tracker_enters_next_open_and_stops_out_with_slippage(): void
    {
        $dates = $this->seedBars('ABC', []);
        $tracker = app(ResearchSignalTracker::class);
        $tracker->record(app(GapHoldDeliveryDetector::class)->scan());
        $signal = ResearchSignal::first();
        $this->assertSame(ResearchSignal::PENDING, $signal->status);

        // next session opens at 105, then falls through the stop the day after
        NseDailyBar::create(['date' => $dates[40], 'symbol' => 'ABC', 'series' => 'EQ', 'open' => 105, 'high' => 106, 'low' => 104,
            'close' => 105, 'volume' => 1e6, 'turnover' => 1e8, 'deliv_pct' => 40]);
        NseDailyBar::create(['date' => $dates[41], 'symbol' => 'ABC', 'series' => 'EQ', 'open' => 104, 'high' => 104.5, 'low' => 90,
            'close' => 91, 'volume' => 1e6, 'turnover' => 1e8, 'deliv_pct' => 40]);

        $tracker->resolve();
        $signal->refresh();

        $this->assertSame(ResearchSignal::CLOSED, $signal->status);
        $this->assertSame('stop', $signal->exit_reason);
        $this->assertEqualsWithDelta(105 * 1.001, $signal->entry_price, 1e-6);
        $this->assertEqualsWithDelta($signal->stop_price * 0.9975, $signal->exit_price, 1e-6);
        $this->assertLessThan(-1.0, $signal->r_multiple); // a full stop plus charges and slippage
    }

    public function test_entry_gapping_more_than_two_percent_is_not_taken(): void
    {
        $dates = $this->seedBars('ABC', []);
        $tracker = app(ResearchSignalTracker::class);
        $tracker->record(app(GapHoldDeliveryDetector::class)->scan());
        NseDailyBar::create(['date' => $dates[40], 'symbol' => 'ABC', 'series' => 'EQ', 'open' => 109, 'high' => 110, 'low' => 108,
            'close' => 109, 'volume' => 1e6, 'turnover' => 1e8, 'deliv_pct' => 40]);

        $tracker->resolve();

        $this->assertSame(ResearchSignal::NO_ENTRY, ResearchSignal::first()->status);
    }

    public function test_bhavcopy_parser_keeps_main_board_rows_and_the_file_date(): void
    {
        $csv = "SYMBOL, SERIES, DATE1, PREV_CLOSE, OPEN_PRICE, HIGH_PRICE, LOW_PRICE, LAST_PRICE, CLOSE_PRICE, AVG_PRICE, TTL_TRD_QNTY, TURNOVER_LACS, NO_OF_TRADES, DELIV_QTY, DELIV_PER\n"
            . "ABC, EQ, 25-Nov-2026, 100.00, 103.00, 106.00, 102.50, 105.40, 105.50, 104.90, 3000000, 3147.00, 9000, 1500000, 50.00\n"
            . "XYZ, BE, 25-Nov-2026, 40.00, 41.00, 42.00, 40.50, 41.50, 41.50, 41.20, 1000, 0.41, 20, -, -\n"
            . "SMEC, SM, 25-Nov-2026, 40.00, 41.00, 42.00, 40.50, 41.50, 41.50, 41.20, 1000, 0.41, 20, 1000, 100.00\n";
        Http::fake(['*' => Http::response(str_pad($csv, 600, "\n"))]);

        $r = app(NseBhavcopyService::class)->import(Carbon::parse('2026-11-26')); // a holiday file carries the previous date

        $this->assertSame('2026-11-25', $r['date']);
        $this->assertSame(2, NseDailyBar::count());
        $this->assertNull(NseDailyBar::where('symbol', 'XYZ')->first()->deliv_pct);
        $this->assertEqualsWithDelta(3147 * 100000, NseDailyBar::where('symbol', 'ABC')->first()->turnover, 1);
    }

    public function test_pages_render_without_research_files_or_bars(): void
    {
        config(['research.path' => sys_get_temp_dir() . '/no-research-here']);

        $this->get(route('research.index'))->assertOk()->assertSee('Research outputs not found');
        $this->get(route('research.signals'))->assertOk()->assertSee('NOT VALIDATED');
        $this->get('/research/charts/..%2F.env')->assertNotFound();
    }

    public function test_dashboard_renders_real_research_outputs_when_present(): void
    {
        if (! is_file(base_path('research/strategy_comparison.csv'))) {
            $this->markTestSkipped('research outputs not generated');
        }

        $this->get(route('research.index', ['period' => 'Test']))->assertOk()->assertSee('Gap-and-Hold')->assertSee('Backtested, not live');
    }
}
