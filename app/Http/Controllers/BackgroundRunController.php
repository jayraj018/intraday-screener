<?php

namespace App\Http\Controllers;

use App\Console\Commands\RunBacktest;
use App\Console\Commands\RunMorningScreener;
use App\Models\ScreenerResult;
use App\Models\StrategyStat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Starts the long artisan commands from a URL and reports their progress.
 *
 * The daily scan and the backtest each take many minutes, but a web request is killed after
 * max_execution_time (30s in production), and Render's free plan has no shell, cron or
 * worker to run them otherwise. So the request launches the command as a separate CLI
 * process — which has no time limit and doesn't hold the single PHP-FPM worker — and returns.
 */
class BackgroundRunController extends Controller
{
    /**
     * A run whose heartbeat is older than this is assumed dead. Free instances sleep after
     * 15 idle minutes and restart on deploy, killing the process without it recording why.
     */
    protected const STALE_AFTER_MINUTES = 10;

    /** URL name => the command it runs and the cache key that command reports progress to. */
    protected const JOBS = [
        'screener' => ['command' => 'screener:run', 'status_key' => RunMorningScreener::STATUS_CACHE_KEY],
        'backtest' => ['command' => 'screener:backtest', 'status_key' => RunBacktest::STATUS_CACHE_KEY],
    ];

    public function start(Request $request, string $job)
    {
        if ($denied = $this->denyWithoutToken($request)) {
            return $denied;
        }

        if ($this->isRunning($job)) {
            return response()->json([
                'success' => false,
                'message' => "The {$job} is already running.",
                'status' => $this->statusPayload($job),
            ], 409);
        }

        $command = self::JOBS[$job]['command'];

        // No live run exists, so any isolation lock left behind belongs to a killed process
        Cache::lock('framework'.DIRECTORY_SEPARATOR.'command-'.$command)->forceRelease();

        Cache::put(self::JOBS[$job]['status_key'], [
            'state' => 'starting',
            'started_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
        ], now()->addDay());

        $this->launchInBackground("{$command} --isolated", $job);

        return response()->json([
            'success' => true,
            'message' => ucfirst($job) . " started in the background. Check /api/{$job}-status for progress.",
        ], 202);
    }

    public function status(string $job)
    {
        return response()->json($this->statusPayload($job));
    }

    protected function statusPayload(string $job): array
    {
        return ['run' => Cache::get(self::JOBS[$job]['status_key'], ['state' => 'never_run'])] + match ($job) {
            'screener' => ['setups_today' => ScreenerResult::where('scan_date', now()->toDateString())->count()],
            'backtest' => ['strategies_saved' => StrategyStat::count(), 'last_saved_at' => StrategyStat::max('updated_at')],
        };
    }

    protected function isRunning(string $job): bool
    {
        $run = Cache::get(self::JOBS[$job]['status_key']);

        return $run
            && in_array($run['state'], ['starting', 'running'], true)
            && now()->diffInMinutes($run['heartbeat_at'] ?? $run['started_at'], true) < self::STALE_AFTER_MINUTES;
    }

    protected function denyWithoutToken(Request $request)
    {
        $expected = config('screener.backtest_token');

        if (! $expected) {
            return response()->json([
                'success' => false,
                'message' => 'Background runs are disabled. Set BACKTEST_TOKEN to enable them.',
            ], 403);
        }

        if (! hash_equals($expected, (string) $request->query('token'))) {
            return response()->json(['success' => false, 'message' => 'Invalid token.'], 403);
        }

        return null;
    }

    protected function launchInBackground(string $arguments, string $job): void
    {
        // Under PHP-FPM, PHP_BINARY is the FPM daemon; this finds the CLI `php` on PATH instead
        $php = escapeshellarg((new PhpExecutableFinder)->find(false) ?: 'php');
        $artisan = escapeshellarg(base_path('artisan'));
        $log = escapeshellarg(storage_path("logs/{$job}.log"));

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B {$php} {$artisan} {$arguments} > {$log} 2>&1", 'r'));

            return;
        }

        // nohup + & detach the process so it keeps running after this request has finished
        exec("nohup {$php} {$artisan} {$arguments} > {$log} 2>&1 &");
    }
}
