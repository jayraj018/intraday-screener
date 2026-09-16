<?php

namespace App\Http\Controllers;

use App\Console\Commands\RunBacktest;
use App\Models\StrategyStat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

class BacktestController extends Controller
{
    /**
     * A run whose heartbeat is older than this is assumed dead. Free instances sleep after
     * 15 idle minutes and restart on deploy, killing the process without it recording why.
     */
    protected const STALE_AFTER_MINUTES = 10;

    /**
     * Start `screener:backtest` as a separate background process and return immediately.
     *
     * The backtest replays ~500 stocks and takes many minutes, but a web request is killed
     * after max_execution_time (30s in production) — which is what happened when this route
     * called Artisan::call() directly. A CLI process has no such limit, and it doesn't hold
     * the single PHP-FPM worker while it runs.
     */
    public function start(Request $request)
    {
        if ($denied = $this->denyWithoutToken($request)) {
            return $denied;
        }

        if ($this->isRunning()) {
            return response()->json([
                'success' => false,
                'message' => 'A backtest is already running.',
                'status' => $this->statusPayload(),
            ], 409);
        }

        // No live run exists, so any isolation lock left behind belongs to a killed process
        Cache::lock('framework'.DIRECTORY_SEPARATOR.'command-screener:backtest')->forceRelease();

        Cache::put(RunBacktest::STATUS_CACHE_KEY, [
            'state' => 'starting',
            'started_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
        ], now()->addDay());

        $this->launchInBackground('screener:backtest --isolated');

        return response()->json([
            'success' => true,
            'message' => 'Backtest started in the background. Check /api/backtest-status for progress.',
        ], 202);
    }

    public function status()
    {
        return response()->json($this->statusPayload());
    }

    protected function statusPayload(): array
    {
        return [
            'run' => Cache::get(RunBacktest::STATUS_CACHE_KEY, ['state' => 'never_run']),
            'strategies_saved' => StrategyStat::count(),
            'last_saved_at' => StrategyStat::max('updated_at'),
        ];
    }

    protected function isRunning(): bool
    {
        $run = Cache::get(RunBacktest::STATUS_CACHE_KEY);

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
                'message' => 'The backtest trigger is disabled. Set BACKTEST_TOKEN to enable it.',
            ], 403);
        }

        if (! hash_equals($expected, (string) $request->query('token'))) {
            return response()->json(['success' => false, 'message' => 'Invalid token.'], 403);
        }

        return null;
    }

    protected function launchInBackground(string $arguments): void
    {
        // Under PHP-FPM, PHP_BINARY is the FPM daemon; this finds the CLI `php` on PATH instead
        $php = escapeshellarg((new PhpExecutableFinder)->find(false) ?: 'php');
        $artisan = escapeshellarg(base_path('artisan'));
        $log = escapeshellarg(storage_path('logs/backtest.log'));

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start \"\" /B {$php} {$artisan} {$arguments} > {$log} 2>&1", 'r'));

            return;
        }

        // nohup + & detach the process so it keeps running after this request has finished
        exec("nohup {$php} {$artisan} {$arguments} > {$log} 2>&1 &");
    }
}
