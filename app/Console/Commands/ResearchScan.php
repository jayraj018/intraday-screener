<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\NseDailyBar;
use App\Services\NseService;
use App\Services\Research\GapHoldDeliveryDetector;
use App\Services\Research\NseBhavcopyService;
use App\Services\Research\ResearchSignalTracker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Carbon;

/**
 * After the close (NSE publishes the file around 18:00 IST):
 *   php artisan research:scan            fetch the day's bhavcopy, detect, record, resolve
 *   php artisan research:scan --backfill=40   first run: store enough history for the averages and ATR
 *
 * Run daily from cron-job.org through /api/run-research-scan?token=... (after 18:15 IST).
 * The first run stores enough history by itself. It only writes the research tables and
 * never touches the swing scan, the paper account, Top Picks or Telegram.
 */
class ResearchScan extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'research-scan.status';

    protected $signature = 'research:scan {--date= : session to scan (YYYY-MM-DD), default latest stored}
                            {--backfill=0 : make sure this many sessions of NSE bars are stored first}
                            {--no-fetch : use stored bars only}
                            {--universe=nifty500 : NSE index list to restrict to, or "all"}';

    protected $description = 'Research-watch pattern (Gap-and-Hold with Delivery): fetch NSE bhavcopy, detect, paper-track';

    public function handle(NseBhavcopyService $bhav, GapHoldDeliveryDetector $detector, ResearchSignalTracker $tracker, NseService $nse): int
    {
        return $this->withRunStatus(fn () => $this->scan($bhav, $detector, $tracker, $nse));
    }

    protected function scan(NseBhavcopyService $bhav, GapHoldDeliveryDetector $detector, ResearchSignalTracker $tracker, NseService $nse): int
    {
        $date = $this->option('date');

        if (! $this->option('no-fetch')) {
            $day = $date ? Carbon::parse($date, 'Asia/Kolkata') : now('Asia/Kolkata');
            $backfill = (int) $this->option('backfill');

            // Run from a URL there is no way to pass --backfill, so a first run (or one after
            // the table was emptied) fetches the history the averages and ATR need by itself
            $needed = config('research.gap_hold_delivery.min_history') + 5;

            if ($backfill === 0 && NseDailyBar::query()->distinct()->count('date') < $needed) {
                $backfill = $needed;
                $this->comment("Fewer than {$needed} sessions stored: fetching them first.");
            }

            if ($backfill > 0) {
                foreach ($bhav->backfill($backfill, $day) as $row) {
                    $this->line(sprintf('  %s -> %s %s (%d rows)', $row['asked'], $row['date'] ?? '-', $row['status'], $row['rows']));
                }
            } else {
                $r = $bhav->import($day);
                $this->info("Bhavcopy {$day->toDateString()}: {$r['status']}, session {$r['date']}, {$r['rows']} rows");
            }
        }

        $symbols = $this->option('universe') === 'all' ? null : ($nse->indexSymbols($this->option('universe')) ?: null);

        if ($symbols === null && $this->option('universe') !== 'all') {
            $this->warn('Index list unavailable; scanning every stored EQ symbol that passes the liquidity filter.');
        }

        $scan = $detector->scan($date, $symbols);
        $this->info(sprintf('Session %s: %d evaluated, %d matches, %d near misses', $scan['date'] ?? '-', $scan['evaluated'], count($scan['matches']), count($scan['near_misses'])));

        foreach ($scan['matches'] as $m) {
            $this->line(sprintf('  %-12s close %.2f stop %.2f (%.1f%% risk)  rvol %.1fx', $m['symbol'], $m['close'], $m['plan']['stop'], $m['plan']['risk_percent'], $m['relative_volume']));
        }

        foreach ($scan['skipped'] as $reason => $n) {
            $this->line("  skipped {$n}: {$reason}");
        }

        $new = $tracker->record($scan);
        $res = $tracker->resolve();
        $this->info("Recorded {$new} new signal(s); resolved {$res['resolved']}, open {$res['still_open']}.");

        return self::SUCCESS;
    }
}
