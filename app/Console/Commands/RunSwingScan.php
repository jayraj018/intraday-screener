<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Services\ScreenerService;
use App\Services\Swing\SwingScannerService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class RunSwingScan extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'swing.status';

    protected $signature = 'swing:scan {--symbols= : comma-separated list; defaults to the whole watchlist}';

    protected $description = 'Run the seven swing strategies across the watchlist and store what they found';

    public function handle(SwingScannerService $scanner, ScreenerService $screener): int
    {
        return $this->withRunStatus(fn () => $this->scan($scanner, $screener));
    }

    protected function scan(SwingScannerService $scanner, ScreenerService $screener): int
    {
        $symbols = $this->option('symbols')
            ? array_map('trim', explode(',', $this->option('symbols')))
            : $screener->watchlist();

        $this->info('Running ' . count($scanner->strategies()) . ' swing strategies across ' . count($symbols) . ' stocks...');

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();
        $done = 0;

        $result = $scanner->scan($symbols, function () use ($bar, &$done, $symbols) {
            $bar->advance();
            $this->recordProgress(++$done, count($symbols));
        });

        $bar->finish();
        $this->newLine(2);

        if ($result['scanned'] === 0) {
            $this->error('No stock had enough history to scan. Run candles:backfill first.');

            return self::FAILURE;
        }

        $this->info(sprintf('%d stocks scanned, %d signals stored.', $result['scanned'], $result['stored']));
        $this->newLine();

        $this->table(
            ['State', 'Count', 'What it means'],
            collect([
                'READY' => 'All conditions met; a plan with an entry, stop and targets',
                'WATCHLIST' => 'Close to triggering; nothing to do yet',
                'EXTENDED' => 'The setup was valid but price has already run',
                'NO_SETUP' => 'Nothing (not stored)',
            ])->map(fn ($meaning, $state) => [$state, number_format($result['states'][$state] ?? 0), $meaning])
                ->values()->all()
        );

        $this->newLine();
        $this->comment('Scores and thresholds are NOT TESTED — no strategy here has been validated out of sample.');
        $this->comment('View them at /swing');

        return self::SUCCESS;
    }
}
