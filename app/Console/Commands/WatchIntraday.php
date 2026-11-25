<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Services\IntradayAlertService;
use App\Services\IntradayPickService;
use App\Services\IntradayPracticeService;
use App\Services\NseService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Cache;

/** Meant to be run every few minutes from the open until just after the close. */
class WatchIntraday extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'screener-watch.status';

    protected $signature = 'screener:watch';

    protected $description = 'Send intraday practice messages: when to enter, when the trade is over, and the day\'s result';

    public function handle(
        IntradayAlertService $alerts,
        IntradayPickService $picks,
        IntradayPracticeService $practice,
        NseService $nse,
        TelegramService $telegram,
    ): int {
        return $this->withRunStatus(fn () => $this->watch($alerts, $picks, $practice, $nse, $telegram));
    }

    protected function watch(
        IntradayAlertService $alerts,
        IntradayPickService $picks,
        IntradayPracticeService $practice,
        NseService $nse,
        TelegramService $telegram,
    ): int {
        $now = now('Asia/Kolkata');
        $today = $now->toDateString();
        $exits = $alerts->follow();

        if ($exits) {
            $this->info('Exit alert(s) sent: ' . implode(', ', $exits));
        }

        $picked = $picks->today()->isNotEmpty();

        if (config('screener.alerts.enabled') && $alerts->isOpen() && ! $picked) {
            $this->warn('No stocks were picked today.');

            if ($nse->isTradingDay($now)) {
                $this->once($telegram, "missing.{$today}", '🚨 Intraday practice: no stocks were picked today, so there will be no practice trades. (screener:picks did not run.)');
            }

            return self::SUCCESS;
        }

        $result = $alerts->watch();

        $this->info(sprintf('%d watched, %d alert(s) sent%s', $result['watched'], count($result['sent']), $result['sent'] ? ': ' . implode(', ', $result['sent']) : ''));

        if ($result['reason']) {
            $this->comment($result['reason']);
        }

        if ($picked && $now->format('H:i') >= config('screener.alerts.summary_after')) {
            $this->once($telegram, "summary.{$today}", fn () => $practice->summary($today));
        }

        return self::SUCCESS;
    }

    /** Sends a message once, and tries again on the next run if it could not be delivered. */
    protected function once(TelegramService $telegram, string $key, string|\Closure $text): void
    {
        $key = "screener-watch.{$key}";

        if (! Cache::has($key) && $telegram->send(value($text))) {
            Cache::put($key, true, now()->addDay());
        }
    }
}
