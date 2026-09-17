<?php

namespace App\Services;

use App\Models\BacktestTrade;
use Illuminate\Support\Facades\DB;

/**
 * Turns a run's trades into the numbers that decide whether a strategy is worth trading.
 *
 * Win rate on its own settles nothing: at the 1:2 payoff these strategies use, 34% wins
 * is already break-even, so a 48% win rate could mean a strong edge or nothing at all
 * depending on where the losers land. Profit factor and expectancy are what separate
 * those two cases.
 */
class BacktestMetricsService
{
    /**
     * Per-strategy metrics for one run, keyed by strategy name.
     *
     * The aggregates are done in Postgres rather than by loading trades into PHP — a
     * Nifty 500 run stores around a hundred thousand of them.
     */
    public function forRun(int $runId): array
    {
        $rows = BacktestTrade::query()
            ->where('run_id', $runId)
            ->groupBy('strategy')
            ->select('strategy')
            ->selectRaw('COUNT(*) AS trades')
            ->selectRaw('COUNT(*) FILTER (WHERE net_pnl > 0) AS wins')
            ->selectRaw('AVG(r_multiple) FILTER (WHERE net_pnl > 0) AS avg_win_r')
            ->selectRaw('AVG(r_multiple) FILTER (WHERE net_pnl <= 0) AS avg_loss_r')
            ->selectRaw('COALESCE(SUM(r_multiple) FILTER (WHERE net_pnl > 0), 0) AS gross_profit_r')
            ->selectRaw('COALESCE(-SUM(r_multiple) FILTER (WHERE net_pnl <= 0), 0) AS gross_loss_r')
            ->selectRaw('AVG(r_multiple) AS expectancy_r')
            ->selectRaw('SUM(r_multiple) AS total_return_r')
            ->selectRaw('SUM(net_pnl) AS net_pnl')
            ->selectRaw('AVG(bars_held) AS avg_bars_held')
            ->selectRaw('MAX(r_multiple) AS best_trade_r')
            ->selectRaw('MIN(r_multiple) AS worst_trade_r')
            ->get();

        $sequences = $this->sequenceMetrics($runId);
        $metrics = [];

        foreach ($rows as $row) {
            $trades = (int) $row->trades;
            $wins = (int) $row->wins;

            $metrics[$row->strategy] = [
                'trades' => $trades,
                'wins' => $wins,
                'win_rate' => round($wins / $trades * 100, 2),
                'avg_win_r' => $this->round($row->avg_win_r),
                'avg_loss_r' => $this->round($row->avg_loss_r),

                // Undefined rather than infinite when a strategy never lost: reporting a
                // huge number would read as a great result instead of too few trades.
                'profit_factor' => $row->gross_loss_r > 0
                    ? round($row->gross_profit_r / $row->gross_loss_r, 4)
                    : null,

                'expectancy_r' => $this->round($row->expectancy_r),
                'total_return_r' => $this->round($row->total_return_r),
                'net_pnl' => round((float) $row->net_pnl, 2),
                'avg_bars_held' => $this->round($row->avg_bars_held, 2),
                'best_trade_r' => $this->round($row->best_trade_r),
                'worst_trade_r' => $this->round($row->worst_trade_r),
                ...$sequences[$row->strategy] ?? ['max_drawdown_r' => null, 'max_consecutive_losses' => null],
            ];
        }

        return $metrics;
    }

    /**
     * Drawdown and losing streaks, which depend on the order trades happened in and so
     * can't come from an aggregate. Walked with a cursor to keep memory flat.
     */
    protected function sequenceMetrics(int $runId): array
    {
        $state = [];

        $trades = BacktestTrade::query()
            ->where('run_id', $runId)
            ->orderBy('strategy')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->select(['strategy', 'r_multiple', 'net_pnl'])
            ->cursor();

        foreach ($trades as $trade) {
            $s = &$state[$trade->strategy];
            $s ??= ['equity' => 0.0, 'peak' => 0.0, 'drawdown' => 0.0, 'streak' => 0, 'worst_streak' => 0];

            $s['equity'] += $trade->r_multiple;
            $s['peak'] = max($s['peak'], $s['equity']);
            $s['drawdown'] = max($s['drawdown'], $s['peak'] - $s['equity']);

            if ($trade->net_pnl > 0) {
                $s['streak'] = 0;
            } else {
                $s['streak']++;
                $s['worst_streak'] = max($s['worst_streak'], $s['streak']);
            }

            unset($s);
        }

        return array_map(fn ($s) => [
            'max_drawdown_r' => round($s['drawdown'], 4),
            'max_consecutive_losses' => $s['worst_streak'],
        ], $state);
    }

    /**
     * How trades ended, per strategy. This is the diagnostic that says what a win rate
     * is actually measuring: if most trades finish at 'session_close' rather than at a
     * stop or a target, the number is describing next-day drift, not the strategy.
     */
    public function exitBreakdown(int $runId): array
    {
        return BacktestTrade::query()
            ->where('run_id', $runId)
            ->groupBy('strategy', 'exit_reason')
            ->select('strategy', 'exit_reason', DB::raw('COUNT(*) AS trades'))
            ->get()
            ->groupBy('strategy')
            ->map(fn ($rows) => $rows->pluck('trades', 'exit_reason')->all())
            ->all();
    }

    protected function round($value, int $precision = 4): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
