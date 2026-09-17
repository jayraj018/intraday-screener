<?php

namespace App\Http\Controllers;

use App\Models\StrategyStat;

/**
 * Reads the last backtest's results.
 *
 * The metrics that decide whether a strategy is worth trading — profit factor,
 * expectancy, drawdown, how trades actually ended — were only ever printed to the
 * console, which on a hosted instance means a log file nobody can open. This is the
 * same data the dashboard panel renders, in a form that can be checked the moment a
 * run finishes.
 */
class BacktestController extends Controller
{
    public function stats()
    {
        $stats = StrategyStat::with('run')->orderByDesc('expectancy_r')->get();

        if ($stats->isEmpty()) {
            return response()->json([
                'run' => null,
                'strategies' => [],
                'message' => 'No backtest has been recorded yet. Start one with /api/run-backtest?token=...',
            ]);
        }

        $run = $stats->first()->run;

        return response()->json([
            'label' => 'BACKTEST RESULT',
            'caveats' => [
                'In-sample: the whole period was replayed at once, with no out-of-sample or walk-forward split.',
                'The stock universe is today\'s index membership replayed backwards, so delisted and demoted stocks are missing (survivorship bias).',
                'Split and dividend adjustment in the price feed has not been verified.',
                'These are backtest results on past data, net of costs. They are not a prediction and not a live or paper-traded result.',
            ],
            'run' => $run ? [
                'id' => $run->id,
                'range' => $run->range,
                'symbols' => $run->symbols,
                'finished_at' => $run->finished_at?->toIso8601String(),
                'entry_model' => $run->settings['execution']['entry'] ?? null,
                'settings' => $run->settings,
            ] : null,
            'qualifying_rules' => [
                'min_trades' => config('screener.min_backtest_trades'),
                'min_win_rate' => config('screener.min_win_rate'),
                'min_expectancy_r' => config('screener.min_expectancy_r'),
            ],
            'strategies' => $stats->map(fn (StrategyStat $s) => [
                'strategy' => $s->strategy,
                'trades' => $s->trades,
                'wins' => $s->wins,
                'win_rate' => $s->win_rate,
                'avg_win_r' => $s->avg_win_r,
                'avg_loss_r' => $s->avg_loss_r,
                'profit_factor' => $s->profit_factor,
                'expectancy_r' => $s->expectancy_r,
                'max_drawdown_r' => $s->max_drawdown_r,
                'total_return_r' => $s->total_return_r,
                'net_pnl' => $s->net_pnl,
                'avg_bars_held' => $s->avg_bars_held,
                'max_consecutive_losses' => $s->max_consecutive_losses,
                'best_trade_r' => $s->best_trade_r,
                'worst_trade_r' => $s->worst_trade_r,
                'exit_breakdown' => $s->exit_breakdown,
                'counts_in_top_picks' => $s->qualifies(),
            ])->values(),
        ]);
    }
}
