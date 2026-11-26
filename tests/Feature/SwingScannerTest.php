<?php

namespace Tests\Feature;

use App\Models\SwingSignal;
use App\Services\StockDataService;
use App\Services\Swing\SwingScannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SwingScannerTest extends TestCase
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

    /** Weekday candles ending on $last, rising steadily so MA Trend has a setup to find. */
    protected function rising(string $last, float $step, int $count = 320): array
    {
        $dates = [];

        for ($day = Carbon::parse($last); count($dates) < $count; $day->subDay()) {
            if (! $day->isWeekend()) {
                $dates[] = $day->toDateString();
            }
        }

        return array_map(function ($date, $i) use ($step) {
            $price = 100 + $i * $step;

            return ['date' => $date, 'open' => $price, 'high' => $price + 2, 'low' => $price - 2, 'close' => $price,
                'adj_close' => $price, 'volume' => 1_000_000];
        }, array_reverse($dates), range(0, $count - 1));
    }

    protected function provider(?array $stock, ?array $benchmark): void
    {
        $this->mock(StockDataService::class, function ($mock) use ($stock, $benchmark) {
            $mock->shouldReceive('getDailyCandles')->andReturnUsing(fn ($symbol) => $symbol === '^NSEI' ? $benchmark : $stock);
            $mock->shouldReceive('getWeeklyCandles')->andReturn([]);
        });
    }

    protected function scan(): array
    {
        return app(SwingScannerService::class)->scan(['TESTCO']);
    }

    /** The daily cron's whole purpose: the second day's scan must read the second day's price. */
    public function test_a_second_scan_uses_the_new_days_prices(): void
    {
        $this->at('2026-11-19 16:30');
        $this->provider($this->rising('2026-11-19', 0.9), $this->rising('2026-11-19', 0.3));
        $this->scan();
        $first = SwingSignal::where('strategy', 'MA Trend Following')->value('signal_price');

        $this->at('2026-11-20 16:30');
        $this->provider($this->rising('2026-11-20', 0.9, 321), $this->rising('2026-11-20', 0.3, 321));
        $this->scan();
        $second = SwingSignal::whereDate('scan_date', '2026-11-20')->where('strategy', 'MA Trend Following')->value('signal_price');

        $this->assertNotNull($first);
        $this->assertEqualsWithDelta($first + 0.9, $second, 0.001);
    }

    public function test_a_stock_that_cannot_be_refreshed_gets_no_signal(): void
    {
        $this->at('2026-11-19 16:30');
        $this->provider($this->rising('2026-11-19', 0.9), $this->rising('2026-11-19', 0.3));
        $this->scan();

        $this->at('2026-11-20 16:30');
        $this->provider(null, $this->rising('2026-11-20', 0.3, 321));
        $result = $this->scan();

        $this->assertSame(1, $result['stale']);
        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, SwingSignal::whereDate('scan_date', '2026-11-20')->count());
    }

    public function test_a_scan_without_a_current_benchmark_fails_and_keeps_the_last_one(): void
    {
        $this->at('2026-11-19 16:30');
        $this->provider($this->rising('2026-11-19', 0.9), $this->rising('2026-11-19', 0.3));
        $this->scan();
        $stored = SwingSignal::count();

        $this->at('2026-11-20 16:30');
        $this->provider($this->rising('2026-11-20', 0.9), null);

        try {
            $this->scan();
            $this->fail('the scan ran on a stale benchmark');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NIFTY', $e->getMessage());
        }

        $this->assertGreaterThan(0, $stored);
        $this->assertSame($stored, SwingSignal::count());
    }

    public function test_no_disabled_strategy_reaches_the_stored_signals(): void
    {
        $this->at('2026-11-19 16:30');
        $this->provider($this->rising('2026-11-19', 0.9), $this->rising('2026-11-19', 0.3));
        $this->scan();

        $this->assertSame(0, SwingSignal::whereIn('strategy', config('swing.disabled_strategies'))->count());
    }

    public function test_the_dashboard_puts_a_stock_with_only_watch_only_setups_in_its_own_section(): void
    {
        foreach ([['MOMO', 'Momentum Continuation'], ['TREND', 'MA Trend Following'], ['TREND', 'Trend Pullback']] as [$symbol, $strategy]) {
            SwingSignal::create([
                'scan_date' => '2026-11-23', 'signal_date' => '2026-11-23', 'symbol' => $symbol, 'strategy' => $strategy, 'state' => 'READY',
                'direction' => 'BUY', 'score' => $symbol === 'MOMO' ? 99 : 10, 'signal_price' => 498, 'entry_trigger' => null, 'stop_price' => 480,
                'stop_method' => 'atr', 'target1' => 534, 'target2' => 570, 'target1_r' => 2, 'target2_r' => 4, 'checks' => [],
            ]);
        }

        $this->get('/swing')->assertOk()
            ->assertSeeInOrder(['Ready now', 'TREND', 'Trend Pullback · watch only', 'Watch only', 'MOMO'])
            ->assertSee('info only');
    }
}
