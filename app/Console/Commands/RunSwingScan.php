<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Services\ScreenerService;
use App\Services\Swing\SwingAlertService;
use App\Services\Swing\SwingScannerService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class RunSwingScan extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'swing.status';

    protected $signature = 'swing:scan {--symbols= : comma-separated list; defaults to the whole watchlist}';

    protected $description = 'Run the enabled swing strategies across the watchlist and store what they found';

    protected array $result = [];

    public function handle(SwingScannerService $scanner, ScreenerService $screener, SwingAlertService $alerts): int
    {
        // A scan of chosen stocks is somebody looking at something, not the daily run
        $announce = ! $this->option('symbols');

        try {
            $exit = $this->withRunStatus(fn () => $this->scan($scanner, $screener));
        } catch (\Throwable $e) {
            // Our own refusals explain themselves; a database error would quote its query
            $announce && $alerts->scanFailed($e::class === \RuntimeException::class ? $e->getMessage() : class_basename($e) . ' — see the logs.');

            throw $e;
        }

        if ($announce) {
            $this->announce($alerts, $exit);
        }

        return $exit;
    }

    protected function announce(SwingAlertService $alerts, int $exit): void
    {
        try {
            $exit === self::SUCCESS
                ? $alerts->scanFinished($this->result)
                : $alerts->scanFailed('No stock could be scanned: prices could not be refreshed.');
        } catch (\Throwable $e) {
            report($e);
            $this->warn('The alert could not be sent: ' . class_basename($e));
        }
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

        $result = $this->result = $scanner->scan($symbols, function () use ($bar, &$done, $symbols) {
            $bar->advance();
            $this->recordProgress(++$done, count($symbols));
        }, paper: ! $this->option('symbols'));

        $bar->finish();
        $this->newLine(2);

        if ($result['stale'] > 0) {
            $this->warn($result['stale'] . ' stock(s) skipped: their prices could not be refreshed.');
        }

        if ($result['scanned'] === 0) {
            $this->error('No stock could be scanned: prices could not be refreshed, or there is too little history.');

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

        $this->reportPaperLog($result['paper']);

        $this->newLine();
        $this->comment('Scores and thresholds are NOT TESTED — no strategy here has been validated out of sample.');
        $this->comment('View them at /swing');

        return self::SUCCESS;
    }

    protected function reportPaperLog(?array $paper): void
    {
        $this->newLine();

        if ($paper === null) {
            $this->comment('Paper log not updated: it only records scans of the whole list.');

            return;
        }

        if (isset($paper['error'])) {
            $this->error('Paper log failed: ' . $paper['error']);

            return;
        }

        $this->info(sprintf(
            'Paper log: %d plans and %d random entries logged, %d taken by the paper account; %d closed, %d opened, %d not entered.',
            $paper['signals']['logged'], $paper['controls']['logged'], $paper['signals']['taken'],
            $paper['resolved']['closed'], $paper['resolved']['opened'], $paper['resolved']['no_entry'],
        ));

        if ($paper['signals']['late'] > 0) {
            $this->warn($paper['signals']['late'] . ' plan(s) were logged after their entry session opened and will not be counted. Scan after the close.');
        }
    }
}
