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
    public function forRun(int $runId, ?string $system = null): array
    {
        $rows = BacktestTrade::query()
            ->where('run_id', $runId)
            ->when($system, fn ($q) => $q->where('system', $system))
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

            // How trades ended, as rates. A strategy whose stop rate is near its win rate
            // is being decided by its exits, not by its entries.
            ->selectRaw("COUNT(*) FILTER (WHERE exit_reason IN ('target1','target_gap')) AS t1_hits")
            ->selectRaw("COUNT(*) FILTER (WHERE exit_reason = 'target2') AS t2_hits")
            ->selectRaw("COUNT(*) FILTER (WHERE exit_reason IN ('stop','stop_gap','trailing_stop')) AS stop_hits")
            ->selectRaw("COUNT(*) FILTER (WHERE exit_reason IN ('max_holding_days','session_close')) AS time_exits")
            ->get();

        $sequences = $this->sequenceMetrics($runId);
        $medians = $this->medianR($runId);
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
                't1_hit_rate' => round($row->t1_hits / $trades * 100, 2),
                't2_hit_rate' => round($row->t2_hits / $trades * 100, 2),
                'stop_rate' => round($row->stop_hits / $trades * 100, 2),
                'time_exit_rate' => round($row->time_exits / $trades * 100, 2),
                'median_r' => $medians[$row->strategy] ?? null,
                'best_trade_r' => $this->round($row->best_trade_r),
                'worst_trade_r' => $this->round($row->worst_trade_r),
                ...$sequences[$row->strategy] ?? ['max_drawdown_r' => null, 'max_consecutive_losses' => null],
            ];
        }

        return $metrics;
    }

    /**
     * Median R per strategy. The mean is pulled by a single outsized winner; the median
     * says what a typical trade actually returned.
     */
    protected function medianR(int $runId): array
    {
        $byStrategy = [];

        foreach (BacktestTrade::where('run_id', $runId)->orderBy('r_multiple')->cursor() as $trade) {
            $byStrategy[$trade->strategy][] = $trade->r_multiple;
        }

        return array_map(function (array $values) {
            $count = count($values);
            $middle = intdiv($count, 2);

            return round($count % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 4);
        }, $byStrategy);
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
