<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\IntradayAlert;
use App\Services\IntradayPickService;
use App\Services\NseService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Cache;

/** Meant to be run once, before the open. Running it again sends nothing twice. */
class PickIntraday extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'screener-picks.status';

    protected $signature = 'screener:picks';

    protected $description = 'Choose the day\'s intraday practice stocks and send the watchlist to Telegram';

    public function handle(IntradayPickService $picks, NseService $nse, TelegramService $telegram): int
    {
        return $this->withRunStatus(fn () => $this->choose($picks, $nse, $telegram));
    }

    protected function choose(IntradayPickService $service, NseService $nse, TelegramService $telegram): int
    {
        $now = now('Asia/Kolkata');

        if (! config('screener.alerts.enabled')) {
            $this->comment('Intraday practice is off (INTRADAY_ALERTS).');

            return self::SUCCESS;
        }

        if (! $nse->isTradingDay($now)) {
            $this->comment('The market is closed today.');

            return self::SUCCESS;
        }

        if ($now->format('H:i') >= config('screener.live.no_new_entry_after')) {
            $this->comment('Too late to pick stocks for today.');

            return self::SUCCESS;
        }

        $done = 0;

        try {
            // Not an arrow function: those capture $done by value, so it would never go past 1
            $result = $service->pick(function (int $total) use (&$done) {
                $this->recordProgress(++$done, $total);
            });
        } catch (\RuntimeException $e) {
            $this->reportFailure($e, $telegram, $now->toDateString());

            return self::FAILURE;
        }

        foreach ($result['picks']->where('kind', IntradayAlert::SIGNAL) as $pick) {
            $this->line("{$pick->position}. {$pick->symbol} — {$pick->reason()}");
        }

        if ($result['skipped']) {
            $this->comment('Left out because of news: ' . implode(', ', $result['skipped']));
        }

        if ($result['news'] === false) {
            $this->warn('The news could not be checked.');
        }

        $service->announce($result)
            ? $this->info('Watchlist sent.')
            : $this->warn('The watchlist could not be sent; run this again to retry.');

        return self::SUCCESS;
    }

    protected function reportFailure(\RuntimeException $e, TelegramService $telegram, string $today): void
    {
        // Our own refusals explain themselves; a database error would quote its query
        $why = $e::class === \RuntimeException::class ? $e->getMessage() : class_basename($e) . ' — see the logs.';
        $key = "screener-picks.failed.{$today}";

        $this->error($why);
        $e::class === \RuntimeException::class || report($e);

        if (! Cache::has($key) && $telegram->send("🚨 Intraday practice: {$why}")) {
            Cache::put($key, true, now()->addDay());
        }
    }
}
