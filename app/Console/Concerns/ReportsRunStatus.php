<?php

namespace App\Console\Concerns;

use Illuminate\Support\Facades\Cache;

/**
 * Lets a long-running command be started from a URL and watched through a status endpoint
 * (see BackgroundRunController), which can't observe the background process directly.
 *
 * Using commands define a STATUS_CACHE_KEY constant and implement Isolatable.
 */
trait ReportsRunStatus
{
    protected string $runStartedAt;

    protected function withRunStatus(callable $work): int
    {
        $this->runStartedAt = now()->toIso8601String();
        $this->recordRunStatus(['state' => 'running', 'heartbeat_at' => $this->runStartedAt]);

        try {
            $result = $work();
        } catch (\Throwable $e) {
            $this->recordRunStatus(['state' => 'failed', 'finished_at' => now()->toIso8601String(), 'error' => $e->getMessage()]);

            throw $e;
        }

        $this->recordRunStatus([
            'state' => $result === self::SUCCESS ? 'finished' : 'failed',
            'finished_at' => now()->toIso8601String(),
        ]);

        return $result;
    }

    /**
     * Heartbeat: if the process dies (free instances sleep and restart), this stops updating
     * and the web trigger knows it may start a new run instead of refusing.
     */
    protected function recordProgress(int $done, int $total): void
    {
        $this->recordRunStatus([
            'state' => 'running',
            'heartbeat_at' => now()->toIso8601String(),
            'progress' => "{$done}/{$total}",
        ]);
    }

    /**
     * On a small instance a run can take well over the default one-hour isolation lock,
     * which would let a second run start on top of the first.
     */
    public function isolationLockExpiresAt()
    {
        return now()->addHours(6);
    }

    protected function recordRunStatus(array $status): void
    {
        Cache::put(static::STATUS_CACHE_KEY, ['started_at' => $this->runStartedAt, ...$status], now()->addDays(7));
    }
}
