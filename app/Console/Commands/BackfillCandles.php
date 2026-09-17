 <?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Services\CandleStore;
use App\Services\ScreenerService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class BackfillCandles extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'candles.status';

    protected $signature = 'candles:backfill
        {--interval=1d : 1d or 1wk}
        {--range=5y : how much history to request}
        {--symbols= : comma-separated list; defaults to the whole watchlist}
        {--fresh : re-download symbols already stored instead of skipping them}';

    protected $description = 'Download daily or weekly candles into the local store, so backtests stop re-downloading and become reproducible';

    public function handle(CandleStore $store, ScreenerService $screener): int
    {
        return $this->withRunStatus(fn () => $this->backfill($store, $screener));
    }

    protected function backfill(CandleStore $store, ScreenerService $screener): int
    {
        $interval = $this->option('interval');

        if (! in_array($interval, ['1d', '1wk'], true)) {
            $this->error("Interval must be 1d or 1wk, not {$interval}.");

            return self::FAILURE;
        }

        $symbols = $this->option('symbols')
            ? array_map('trim', explode(',', $this->option('symbols')))
            : $screener->watchlist();

        // The benchmark is needed for relative strength and market regime, and is not in
        // any index constituent list, so it is always included.
        if (! in_array('^NSEI', $symbols, true)) {
            $symbols[] = '^NSEI';
        }

        $this->info(sprintf(
            'Backfilling %s candles (%s) for %d symbols...',
            $interval, $this->option('range'), count($symbols)
        ));

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();

        $rows = 0;
        $done = 0;
        $skipped = 0;
        $failed = [];

        foreach ($symbols as $symbol) {
            // Resuming after an interruption shouldn't re-download everything. A free
            // instance can be stopped mid-run, and this is a long job.
            if (! $this->option('fresh') && $store->latestDate($symbol, $interval)) {
                $skipped++;
            } else {
                $written = $store->sync($symbol, $interval, $this->option('range'));

                if ($written === 0) {
                    $failed[] = $symbol;
                }

                $rows += $written;
            }

            $bar->advance();
            $this->recordProgress(++$done, count($symbols));
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(number_format($rows) . ' rows written, ' . $skipped . ' symbols already stored.');

        if ($failed) {
            $this->warn(count($failed) . ' symbol(s) returned no data: ' . implode(', ', array_slice($failed, 0, 20))
                . (count($failed) > 20 ? ' …' : ''));
        }

        $this->newLine();
        $this->table(
            ['Interval', 'Symbols', 'Rows', 'From', 'To'],
            collect($store->summary())->map(fn ($s, $i) => [
                $i, number_format($s['symbols']), number_format($s['rows']), $s['first_date'], $s['last_date'],
            ])->values()->all()
        );

        return self::SUCCESS;
    }
}
