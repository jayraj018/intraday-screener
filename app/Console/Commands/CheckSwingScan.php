<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\SwingSignal;
use App\Services\CandleStore;
use App\Services\Swing\SwingAlertService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Cache;

/**
 * Notices the scan that never happened.
 *
 * A scan that fails reports itself. One that was never started, or whose process was killed
 * when the instance went to sleep, cannot — so this is run separately, after it was due.
 */
class CheckSwingScan extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'swing-check.status';

    protected const STALE_AFTER_MINUTES = 10;

    protected $signature = 'swing:check';

    protected $description = 'Send a Telegram alert if today\'s swing scan did not run after the close';

    public function handle(SwingAlertService $alerts): int
    {
        return $this->withRunStatus(fn () => $this->check($alerts));
    }

    protected function check(SwingAlertService $alerts): int
    {
        $now = now('Asia/Kolkata');
        $due = $now->copy()->setTimeFromTimeString(config('screener.market_close'))->addMinutes(CandleStore::SETTLE_MINUTES);

        if ($now->isWeekend() || $now->lt($due)) {
            $this->info('No scan is due yet today.');

            return self::SUCCESS;
        }

        $scanned = SwingSignal::whereDate('scan_date', $now->toDateString())
            ->where('created_at', '>=', $due->copy()->utc())
            ->exists();

        if ($scanned) {
            $this->info('Today\'s scan is in.');

            return self::SUCCESS;
        }

        $run = Cache::get(RunSwingScan::STATUS_CACHE_KEY);
        $running = in_array($run['state'] ?? null, ['starting', 'running'], true);

        if ($running && now()->diffInMinutes($run['heartbeat_at'] ?? $run['started_at'], true) < self::STALE_AFTER_MINUTES) {
            $this->info('The scan is still running.');

            return self::SUCCESS;
        }

        $reason = $running ? 'It started but stopped part way.' : 'It has not run since the market closed.';
        $this->warn($reason);
        $alerts->scanFailed($reason);

        return self::SUCCESS;
    }
}
