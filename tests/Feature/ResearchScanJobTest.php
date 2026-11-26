<?php

namespace Tests\Feature;

use App\Console\Commands\ResearchScan;
use App\Models\NseDailyBar;
use App\Services\Research\NseBhavcopyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The research scan, started from cron-job.org the same way as every other job. */
class ResearchScanJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_can_be_started_and_watched_from_a_url(): void
    {
        $this->get('/api/research-scan-status')->assertOk()->assertJsonPath('run.state', 'never_run')->assertJsonPath('signals_recorded', 0);
        $this->get('/api/run-research-scan')->assertStatus(403);
    }

    public function test_a_first_run_fetches_the_history_it_needs_by_itself(): void
    {
        $this->mock(NseBhavcopyService::class, function ($mock) {
            $mock->shouldReceive('backfill')->once()->withArgs(fn ($sessions) => $sessions === config('research.gap_hold_delivery.min_history') + 5)->andReturn([]);
            $mock->shouldNotReceive('import');
        });

        $this->artisan('research:scan', ['--universe' => 'all'])->expectsOutputToContain('fetching them first')->assertSuccessful();

        $this->assertSame('finished', Cache::get(ResearchScan::STATUS_CACHE_KEY)['state']);
    }

    public function test_once_the_history_is_there_it_only_fetches_the_day(): void
    {
        $needed = config('research.gap_hold_delivery.min_history') + 5;

        foreach (range(1, $needed) as $i) {
            NseDailyBar::create(['symbol' => 'TESTCO', 'series' => 'EQ', 'date' => now()->subDays($i)->toDateString(), 'prev_close' => 100,
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000, 'turnover' => 100000, 'deliv_qty' => 500, 'deliv_pct' => 50]);
        }

        $this->mock(NseBhavcopyService::class, function ($mock) {
            $mock->shouldNotReceive('backfill');
            $mock->shouldReceive('import')->once()->andReturn(['date' => null, 'rows' => 0, 'status' => 'not_published']);
        });

        $this->artisan('research:scan', ['--universe' => 'all'])->assertSuccessful();
    }
}
