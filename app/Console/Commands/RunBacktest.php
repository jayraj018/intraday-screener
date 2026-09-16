<?php

namespace App\Console\Commands;

use App\Models\StrategyStat;
use App\Services\BacktestService;
use App\Services\ScreenerService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Cache;

class RunBacktest extends Command implements Isolatable
{
    /** Progress for /api/backtest-status, which can't see this process directly. */
    public const STATUS_CACHE_KEY = 'backtest.status';

    protected $signature = 'screener:backtest';

    protected $description = 'Replay each strategy on past data, save its same-day win rate, and decide which count towards Top Picks';

    protected string $startedAt;

    public function handle(BacktestService $backtest, ScreenerService $screener): int
    {
        $this->startedAt = now()->toIso8601String();
        $this->recordStatus(['state' => 'running', 'started_at' => $this->startedAt, 'heartbeat_at' => $this->startedAt]);

        try {
            $result = $this->runBacktest($backtest, $screener);
        } catch (\Throwable $e) {
            $this->recordStatus(['state' => 'failed', 'started_at' => $this->startedAt, 'finished_at' => now()->toIso8601String(), 'error' => $e->getMessage()]);

            throw $e;
        }

        $this->recordStatus([
            'state' => $result === self::SUCCESS ? 'finished' : 'failed',
            'started_at' => $this->startedAt,
            'finished_at' => now()->toIso8601String(),
        ]);

        return $result;
    }

    /**
     * On a small instance the run can take well over the default one-hour isolation lock,
     * which would let a second run start on top of the first.
     */
    public function isolationLockExpiresAt()
    {
        return now()->addHours(6);
    }

    protected function runBacktest(BacktestService $backtest, ScreenerService $screener): int
    {
        $symbols = $screener->watchlist();

        $this->info('Backtesting strategies on ' . count($symbols) . ' stocks (trades closed the same day)...');

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();

        $done = 0;
        $tally = $backtest->run($symbols, function () use ($bar, &$done, $symbols) {
            $bar->advance();

            // Heartbeat: if the process dies (free instances sleep and restart), this stops
            // updating and the web trigger knows it may start a new run instead of refusing.
            $this->recordStatus([
                'state' => 'running',
                'started_at' => $this->startedAt,
                'heartbeat_at' => now()->toIso8601String(),
                'progress' => ++$done . '/' . count($symbols),
            ]);
        });
        $bar->finish();
        $this->newLine(2);

        if (! $tally) {
            $this->error('No data could be fetched, so nothing was saved. Try again in a moment.');

            return self::FAILURE;
        }

        // Replace the previous backtest entirely so a strategy that no longer trades doesn't keep an old win rate
        StrategyStat::query()->delete();

        $stats = collect($tally)
            ->map(fn ($t, $strategy) => StrategyStat::create([
                'strategy' => $strategy,
                'trades' => $t['trades'],
                'wins' => $t['wins'],
                'win_rate' => round($t['wins'] / $t['trades'] * 100, 2),
            ]))
            ->sortByDesc('win_rate');

        $this->table(
            ['Strategy', 'Trades', 'Wins', 'Win rate', 'Counts in Top Picks'],
            $stats->map(fn (StrategyStat $s) => [
                $s->strategy,
                $s->trades,
                $s->wins,
                $s->win_rate . '%',
                match (true) {
                    $s->qualifies() => 'Yes',
                    $s->trades < config('screener.min_backtest_trades') => 'No (too few trades)',
                    default => 'No',
                },
            ])
        );

        $this->comment('Positive Earnings can\'t be backtested (no old news data), so it never counts towards Top Picks.');

        return self::SUCCESS;
    }

    protected function recordStatus(array $status): void
    {
        Cache::put(self::STATUS_CACHE_KEY, $status, now()->addDays(7));
    }
}
