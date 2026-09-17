<?php

namespace Tests\Feature;

use App\Models\ScreenerResult;
use App\Models\StrategyStat;
use App\Services\NseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The dashboard fetches board meetings from NSE on every render; the tests
        // shouldn't depend on that being reachable.
        $this->mock(NseService::class, fn ($mock) => $mock->shouldReceive('boardMeetings')->andReturn([]));
    }

    public function test_the_dashboard_loads_with_no_scan_yet(): void
    {
        $this->get('/')->assertOk()->assertSee('No setups found today');
    }

    public function test_the_dashboard_shows_todays_setups(): void
    {
        ScreenerResult::create([
            'symbol' => 'RELIANCE', 'strategy' => 'MA Crossover', 'signal' => 'BUY',
            'entry' => 500, 'stop_loss' => 480, 'target' => 540,
            'volume_surge' => true, 'confidence' => 'higher', 'reason' => 'test',
            'scan_date' => now()->toDateString(),
        ]);

        $this->get('/')->assertOk()->assertSee('RELIANCE');
    }

    /**
     * A strategy that wins often but loses more than it wins must not get a vote in Top
     * Picks. This is the guard on the gate that win rate alone used to control.
     */
    public function test_a_strategy_with_negative_expectancy_does_not_qualify(): void
    {
        $losing = StrategyStat::create([
            'strategy' => 'Looks Good On Paper',
            'trades' => 500, 'wins' => 300, 'win_rate' => 60.0,
            'expectancy_r' => -0.12,
        ]);

        $winning = StrategyStat::create([
            'strategy' => 'Actually Works',
            'trades' => 500, 'wins' => 240, 'win_rate' => 48.0,
            'expectancy_r' => 0.18,
        ]);

        $this->assertFalse($losing->qualifies());
        $this->assertTrue($winning->qualifies());
    }

    public function test_too_few_trades_never_qualifies(): void
    {
        $stat = StrategyStat::create([
            'strategy' => 'Lucky Handful',
            'trades' => 5, 'wins' => 5, 'win_rate' => 100.0,
            'expectancy_r' => 1.5,
        ]);

        $this->assertFalse($stat->qualifies());
    }

    /** A run from before trades were recorded has no expectancy, and must still work. */
    public function test_a_legacy_stat_without_expectancy_falls_back_to_win_rate(): void
    {
        $stat = StrategyStat::create([
            'strategy' => 'Legacy',
            'trades' => 100, 'wins' => 50, 'win_rate' => 50.0,
            'expectancy_r' => null,
        ]);

        $this->assertTrue($stat->qualifies());
    }
}
