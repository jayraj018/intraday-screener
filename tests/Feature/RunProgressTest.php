<?php

namespace Tests\Feature;

use App\Console\Commands\PickIntraday;
use App\Console\Commands\RunMorningScreener;
use App\Services\CandleStore;
use App\Services\NseService;
use App\Services\StockDataService;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The progress a status URL shows has to move with the run. */
class RunProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['screener.alerts.enabled' => true, 'screener.watchlist' => []]);
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-25 09:00', 'Asia/Kolkata')->utc());

        $nse = $this->mock(NseService::class);
        $nse->shouldReceive('isTradingDay')->andReturn(true);
        $nse->shouldReceive('boardMeetings')->andReturn([]);
        $nse->shouldReceive('indexSymbols')->andReturn(['ONE', 'TWO', 'THREE']);

        $this->mock(StockDataService::class)->shouldReceive('getDailyCandles', 'getIntradayCandles')->andReturn(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<int, string> */
    protected function progressOf(string $command, string $statusKey): array
    {
        $seen = [];

        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$seen, $statusKey) {
            if ($event->key === $statusKey && isset($event->value['progress'])) {
                $seen[] = $event->value['progress'];
            }
        });

        $this->artisan($command)->run();

        return $seen;
    }

    public function test_the_morning_screener_counts_every_stock(): void
    {
        // No news request is made: a stock without candles is skipped before it
        $this->assertSame(['1/3', '2/3', '3/3'], $this->progressOf('screener:run', RunMorningScreener::STATUS_CACHE_KEY));
    }

    public function test_picking_counts_every_stock(): void
    {
        // None can be refreshed, so the run fails after counting them all
        $this->mock(CandleStore::class, function ($mock) {
            $mock->shouldReceive('isCurrent')->andReturn(false);
            $mock->shouldReceive('freshen')->andReturn([]);
        });

        $this->assertSame(['1/3', '2/3', '3/3'], $this->progressOf('screener:picks', PickIntraday::STATUS_CACHE_KEY));
    }
}
