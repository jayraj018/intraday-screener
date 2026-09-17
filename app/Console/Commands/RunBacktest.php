<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Models\StrategyStat;
use App\Services\BacktestMetricsService;
use App\Services\BacktestService;
use App\Services\ScreenerService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\DB;

class RunBacktest extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'backtest.status';

    /** Rows per insert. Large enough to be fast, small enough not to build a huge query. */
    protected const INSERT_CHUNK = 500;

    protected $signature = 'screener:backtest';

    protected $description = 'Replay each strategy on past data, record every trade with its costs, and report what each strategy actually returned';

    public function handle(BacktestService $backtest, ScreenerService $screener, BacktestMetricsService $metrics): int
    {
        return $this->withRunStatus(fn () => $this->runBacktest($backtest, $screener, $metrics));
    }

    protected function runBacktest(BacktestService $backtest, ScreenerService $screener, BacktestMetricsService $metrics): int
    {
        $symbols = $screener->watchlist();

        $run = BacktestRun::create([
            'system' => 'intraday',
            'range' => config('screener.backtest_range'),
            'symbols' => count($symbols),
            // Stored so a result can be reproduced, and compared against a later run that
            // changed one of these rather than the code
            'settings' => [
                'execution' => config('screener.execution'),
                'costs' => config('screener.costs'),
                'risk_reward_ratio' => config('screener.risk_reward_ratio'),
                'stop_loss_atr_multiplier' => config('screener.stop_loss_atr_multiplier'),
                'min_avg_volume' => config('screener.min_avg_volume'),
            ],
            'started_at' => now(),
        ]);

        $this->info('Backtesting on ' . count($symbols) . ' stocks (run #' . $run->id . ', entry model: ' . config('screener.execution.entry') . ')...');

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();

        $done = 0;
        $stored = 0;

        $backtest->run(
            $symbols,
            function (array $trades) use ($run, &$stored) {
                $stored += $this->storeTrades($run->id, $trades);
            },
            function () use ($bar, &$done, $symbols) {
                $bar->advance();
                $this->recordProgress(++$done, count($symbols));
            }
        );

        $bar->finish();
        $this->newLine(2);

        if ($stored === 0) {
            $this->error('No trades were produced, so nothing was saved. Check the data provider and try again.');
            $run->delete();

            return self::FAILURE;
        }

        $run->update(['finished_at' => now()]);
        $this->info(number_format($stored) . ' trades recorded.');

        $breakdown = $metrics->exitBreakdown($run->id);

        $this->saveStats($run->id, $metrics->forRun($run->id), $breakdown);
        $this->report($breakdown);

        return self::SUCCESS;
    }

    protected function storeTrades(int $runId, array $trades): int
    {
        $rows = array_map(fn ($trade) => [
            ...$trade,
            'run_id' => $runId,
            'system' => 'intraday',
            'created_at' => now(),
            'updated_at' => now(),
        ], $trades);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            BacktestTrade::insert($chunk);
        }

        return count($rows);
    }

    /**
     * Replace the dashboard's stats in one transaction. Previously a crash between the
     * delete and the inserts left the dashboard reading half a backtest as if it were
     * the whole one.
     */
    protected function saveStats(int $runId, array $metrics, array $breakdown): void
    {
        DB::transaction(function () use ($runId, $metrics, $breakdown) {
            StrategyStat::query()->delete();

            foreach ($metrics as $strategy => $m) {
                StrategyStat::create([
                    'run_id' => $runId,
                    'strategy' => $strategy,
                    'exit_breakdown' => $breakdown[$strategy] ?? null,
                    ...$m,
                ]);
            }
        });
    }

    protected function report(array $breakdown): void
    {
        $stats = StrategyStat::all()->sortByDesc('expectancy_r');

        $this->table(
            ['Strategy', 'Trades', 'Win %', 'Avg win', 'Avg loss', 'Profit factor', 'Expectancy', 'Max DD', 'Net P&L', 'Top Picks'],
            $stats->map(fn (StrategyStat $s) => [
                $s->strategy,
                $s->trades,
                $s->win_rate . '%',
                $this->r($s->avg_win_r),
                $this->r($s->avg_loss_r),
                $s->profit_factor === null ? 'n/a' : number_format($s->profit_factor, 2),
                $this->r($s->expectancy_r),
                $this->r($s->max_drawdown_r),
                '₹' . number_format((float) $s->net_pnl, 0),
                match (true) {
                    $s->qualifies() => 'Yes',
                    $s->trades < config('screener.min_backtest_trades') => 'No (too few trades)',
                    default => 'No',
                },
            ])
        );

        $this->newLine();
        $this->line('<comment>How trades ended</comment> — a strategy whose trades mostly reach "session_close" is being');
        $this->line('measured on next-day drift rather than on its own stop and target.');
        $this->newLine();

        $reasons = ['stop', 'target', 'vwap_trail', 'session_close'];

        $this->table(
            ['Strategy', ...array_map(fn ($r) => str_replace('_', ' ', $r), $reasons)],
            collect($breakdown)->map(function ($counts, $strategy) use ($reasons) {
                $total = array_sum($counts) ?: 1;

                return [$strategy, ...array_map(
                    fn ($r) => isset($counts[$r]) ? $counts[$r] . ' (' . round($counts[$r] / $total * 100) . '%)' : '—',
                    $reasons
                )];
            })->values()->all()
        );

        $this->newLine();
        $this->comment('Expectancy and P&L are net of brokerage, STT, exchange, SEBI, stamp duty, GST and slippage.');
        $this->comment('Positive Earnings can\'t be backtested (no old news data), so it never counts towards Top Picks.');
    }

    protected function r(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 2) . 'R';
    }
}
