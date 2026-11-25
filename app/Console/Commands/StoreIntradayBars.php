<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\IntradayBars;
use App\Services\CandleStore;
use App\Services\ScreenerService;
use App\Services\StockDataService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

/**
 * Keeps 5-minute prices before the provider drops them. Each run saves the last five days
 * again, so a missed day is caught up and a corrected one is replaced.
 */
class StoreIntradayBars extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'intraday-store.status';

    protected $signature = 'intraday:store {--range=5d : how far back to save; the provider keeps 60d} {--symbols= : comma-separated list; defaults to the whole watchlist}';

    protected $description = 'Save 5-minute prices for the whole watchlist';

    public function handle(StockDataService $data, ScreenerService $screener): int
    {
        return $this->withRunStatus(fn () => $this->store($data, $screener));
    }

    protected function store(StockDataService $data, ScreenerService $screener): int
    {
        $symbols = $this->option('symbols')
            ? array_map(fn ($s) => strtoupper(trim($s)), explode(',', $this->option('symbols')))
            : $screener->watchlist();

        $stocks = $days = 0;

        foreach ($symbols as $i => $symbol) {
            $rows = $this->rows($symbol, $data->getIntradayCandles($symbol, $this->option('range')) ?? []);

            if ($rows) {
                IntradayBars::upsert($rows, ['symbol', 'date'], ['count', 'data', 'updated_at']);
                $stocks++;
                $days += count($rows);
            }

            $this->recordProgress($i + 1, count($symbols));
        }

        $this->info("{$days} day(s) of 5-minute prices saved for {$stocks} of " . count($symbols) . ' stocks.');

        return $stocks ? self::SUCCESS : self::FAILURE;
    }

    /** One row per finished session; today's counts once the close has settled. */
    protected function rows(string $symbol, array $candles): array
    {
        $now = now('Asia/Kolkata');
        $settled = $now->copy()->setTimeFromTimeString(config('screener.market_close'))->addMinutes(CandleStore::SETTLE_MINUTES);
        $finished = $now->gte($settled) ? $now->toDateString() : $now->copy()->subDay()->toDateString();

        return collect($candles)
            ->groupBy(fn ($c) => gmdate('Y-m-d', $c['timestamp'] + 19800))
            ->filter(fn ($bars, $date) => $date <= $finished)
            ->map(fn ($bars, $date) => [
                'symbol' => $symbol, 'date' => $date, 'count' => $bars->count(), 'data' => IntradayBars::pack($bars->all()),
                'created_at' => $now->copy()->utc(), 'updated_at' => $now->copy()->utc(),
            ])
            ->values()->all();
    }
}
