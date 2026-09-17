<?php

namespace App\Console\Commands;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Services\BacktestMetricsService;
use Illuminate\Console\Command;

class SwingMetrics extends Command
{
    protected $signature = 'swing:metrics {--run= : run id; defaults to the latest swing run}';

    protected $description = 'Report what a swing backtest actually produced, including a breakdown by market regime';

    public function handle(BacktestMetricsService $metrics): int
    {
        $run = $this->option('run')
            ? BacktestRun::find($this->option('run'))
            : BacktestRun::where('system', 'swing')->latest('id')->first();

        if (! $run) {
            $this->error('No swing backtest has been run. Try: php artisan swing:backtest');

            return self::FAILURE;
        }

        $byStrategy = $metrics->forRun($run->id, system: 'swing');

        if (! $byStrategy) {
            $this->warn("Run #{$run->id} recorded no trades. Nothing to measure.");

            return self::SUCCESS;
        }

        $this->info("Swing backtest run #{$run->id} — {$run->range}, {$run->symbols} symbols");
        $this->newLine();

        $this->table(
            ['Strategy', 'Trades', 'Win %', 'Avg win', 'Avg loss', 'PF', 'Expectancy', 'Median R', 'Max DD', 'Net P&L'],
            collect($byStrategy)->map(fn ($m, $name) => [
                $name,
                $m['trades'],
                $m['win_rate'] . '%',
                $this->r($m['avg_win_r']),
                $this->r($m['avg_loss_r']),
                $m['profit_factor'] === null ? 'n/a' : number_format($m['profit_factor'], 2),
                $this->r($m['expectancy_r']),
                $this->r($m['median_r']),
                $this->r($m['max_drawdown_r']),
                '₹' . number_format((float) $m['net_pnl'], 0),
            ])->values()->all()
        );

        $this->newLine();
        $this->line('<comment>How trades ended.</comment> A strategy whose trades mostly reach a time exit is being');
        $this->line('decided by the clock rather than by its own stop and target.');
        $this->newLine();

        $this->table(
            ['Strategy', 'T1 hit', 'T2 hit', 'Stopped', 'Time exit', 'Avg days', 'Worst streak'],
            collect($byStrategy)->map(fn ($m, $name) => [
                $name,
                $m['t1_hit_rate'] . '%',
                $m['t2_hit_rate'] . '%',
                $m['stop_rate'] . '%',
                $m['time_exit_rate'] . '%',
                number_format($m['avg_bars_held'], 1),
                $m['max_consecutive_losses'],
            ])->values()->all()
        );

        $this->regimeBreakdown($run->id);

        $this->newLine();
        $this->comment('BACKTEST RESULT — in-sample, net of delivery charges and slippage.');
        $this->comment('Not out-of-sample, not walk-forward validated, and not a prediction.');

        return self::SUCCESS;
    }

    /**
     * The same numbers split by the market each trade was taken in.
     *
     * A strategy is not robust because its total is positive: one that only works in a
     * bull market looks fine in aggregate and fails the moment conditions change. The
     * regime is read from the signal date, so it is the market as it was then.
     */
    protected function regimeBreakdown(int $runId): void
    {
        $runner = app(\App\Services\Swing\SwingBacktestRunner::class);
        $buckets = [];
        $regimeCache = [];

        foreach (BacktestTrade::where('run_id', $runId)->where('system', 'swing')->cursor() as $trade) {
            $date = $trade->signal_date->toDateString();
            $regime = $regimeCache[$date] ??= $runner->regimeOn($date);

            foreach ([$regime['trend'] ?? 'UNKNOWN', $regime['volatility'] ?? 'UNKNOWN'] as $bucket) {
                $buckets[$bucket]['trades'][] = $trade->r_multiple;
                $buckets[$bucket]['wins'] = ($buckets[$bucket]['wins'] ?? 0) + ($trade->net_pnl > 0 ? 1 : 0);
            }
        }

        if (! $buckets) {
            return;
        }

        $this->newLine();
        $this->line('<comment>Performance by market regime.</comment> Each trade counts once under a trend and once');
        $this->line('under a volatility state, so the two groups each total the full trade count.');
        $this->newLine();

        $this->table(
            ['Regime', 'Trades', 'Win %', 'Expectancy', 'Total R'],
            collect($buckets)->map(function ($b, $name) {
                $count = count($b['trades']);

                return [
                    str_replace('_', ' ', $name),
                    $count,
                    round(($b['wins'] ?? 0) / $count * 100, 1) . '%',
                    $this->r(array_sum($b['trades']) / $count),
                    $this->r(array_sum($b['trades'])),
                ];
            })->values()->all()
        );
    }

    protected function r(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value, 3) . 'R';
    }
}
