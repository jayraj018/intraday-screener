<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\StrategyStat;
use App\Services\NseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacktestStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(NseService::class, fn ($mock) => $mock->shouldReceive('boardMeetings')->andReturn([]));
    }

    protected function seedRun(): BacktestRun
    {
        $run = BacktestRun::create([
            'system' => 'intraday',
            'range' => '2y',
            'symbols' => 529,
            'settings' => ['execution' => ['entry' => 'next_open']],
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        StrategyStat::create([
            'run_id' => $run->id, 'strategy' => 'MACD Crossover',
            'trades' => 272, 'wins' => 117, 'win_rate' => 43.01,
            'avg_win_r' => 0.31, 'avg_loss_r' => -0.36, 'profit_factor' => 0.64,
            'expectancy_r' => -0.07, 'max_drawdown_r' => 20.93, 'net_pnl' => -19602,
            'exit_breakdown' => ['stop' => 8, 'session_close' => 264],
        ]);

        StrategyStat::create([
            'run_id' => $run->id, 'strategy' => 'Actually Works',
            'trades' => 200, 'wins' => 96, 'win_rate' => 48.0,
            'avg_win_r' => 0.90, 'avg_loss_r' => -0.40, 'profit_factor' => 1.62,
            'expectancy_r' => 0.22, 'max_drawdown_r' => 6.1, 'net_pnl' => 44000,
            'exit_breakdown' => ['stop' => 90, 'target' => 96, 'session_close' => 14],
        ]);

        return $run;
    }

    public function test_the_endpoint_reports_nothing_before_a_backtest_has_run(): void
    {
        $this->getJson('/api/backtest-stats')
            ->assertOk()
            ->assertJson(['run' => null, 'strategies' => []]);
    }

    public function test_the_endpoint_returns_each_strategys_metrics(): void
    {
        $this->seedRun();

        $response = $this->getJson('/api/backtest-stats')->assertOk();

        $response->assertJsonPath('label', 'BACKTEST RESULT');
        $response->assertJsonPath('run.range', '2y');
        $response->assertJsonPath('run.entry_model', 'next_open');
        $response->assertJsonCount(2, 'strategies');

        // Ordered by expectancy, so the profitable strategy comes first
        $response->assertJsonPath('strategies.0.strategy', 'Actually Works');
        $response->assertJsonPath('strategies.0.counts_in_top_picks', true);
        $response->assertJsonPath('strategies.1.strategy', 'MACD Crossover');
        $response->assertJsonPath('strategies.1.counts_in_top_picks', false);
        $response->assertJsonPath('strategies.1.exit_breakdown.session_close', 264);
    }

    /** The result must never be presented without saying what kind of result it is. */
    public function test_the_endpoint_labels_the_result_and_its_limits(): void
    {
        $this->seedRun();

        $response = $this->getJson('/api/backtest-stats')->assertOk();

        $this->assertNotEmpty($response->json('caveats'));
        $this->assertStringContainsString('survivorship', strtolower(implode(' ', $response->json('caveats'))));
    }

    public function test_the_dashboard_shows_the_backtest_panel(): void
    {
        $this->seedRun();

        $this->get('/')
            ->assertOk()
            ->assertSee('Backtest Results')
            ->assertSee('Profit factor')
            ->assertSee('BACKTEST RESULT')
            ->assertSee('lost money after costs')   // the losing strategy's reason
            ->assertSee('session close 97%');       // 264 of 272 trades
    }

    public function test_the_dashboard_has_no_backtest_panel_before_a_run(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Backtest Results');
    }
}
