<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\ScreenerResult;
use App\Services\IntradayAlertService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Cache;

/** Meant to be run every few minutes while the market is open. */
class WatchIntraday extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'screener-watch.status';

    protected $signature = 'screener:watch';

    protected $description = 'Send Telegram alerts for morning-scan BUY stocks: when to enter, and when the trade is over';

    public function handle(IntradayAlertService $alerts, TelegramService $telegram): int
    {
        return $this->withRunStatus(fn () => $this->watch($alerts, $telegram));
    }

    protected function watch(IntradayAlertService $alerts, TelegramService $telegram): int
    {
        $today = now('Asia/Kolkata')->toDateString();
        $exits = $alerts->follow();

        if ($exits) {
            $this->info('Exit alert(s) sent: ' . implode(', ', $exits));
        }

        if (config('screener.alerts.enabled') && $alerts->isOpen() && ! ScreenerResult::whereDate('scan_date', $today)->exists()) {
            $this->warn('The morning scan has no results today.');
            $this->reportMissingScan($telegram, $today);

            return self::SUCCESS;
        }

        $result = $alerts->watch();

        $this->info(sprintf('%d watched, %d alert(s) sent%s', $result['watched'], count($result['sent']), $result['sent'] ? ': ' . implode(', ', $result['sent']) : ''));

        if ($result['reason']) {
            $this->comment($result['reason']);
        }

        return self::SUCCESS;
    }

    /** Once a day: without the scan there is nothing to watch, and silence looks like no trades. */
    protected function reportMissingScan(TelegramService $telegram, string $today): void
    {
        $key = "screener-watch.missing.{$today}";

        if (! Cache::has($key) && $telegram->send('🚨 The intraday scan has no results today, so there will be no BUY alerts. (Market holidays also cause this.)')) {
            Cache::put($key, true, now()->addDay());
        }
    }
}
