<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyStat extends Model
{
    protected $fillable = [
        'run_id',
        'strategy',
        'trades',
        'wins',
        'win_rate',
        'avg_win_r',
        'avg_loss_r',
        'profit_factor',
        'expectancy_r',
        'max_drawdown_r',
        'total_return_r',
        'net_pnl',
        'avg_bars_held',
        'max_consecutive_losses',
        'best_trade_r',
        'worst_trade_r',
        'exit_breakdown',
    ];

    protected $casts = [
        'trades' => 'integer',
        'wins' => 'integer',
        'win_rate' => 'float',
        'avg_win_r' => 'float',
        'avg_loss_r' => 'float',
        'profit_factor' => 'float',
        'expectancy_r' => 'float',
        'max_drawdown_r' => 'float',
        'total_return_r' => 'float',
        'net_pnl' => 'float',
        'avg_bars_held' => 'float',
        'max_consecutive_losses' => 'integer',
        'best_trade_r' => 'float',
        'worst_trade_r' => 'float',
        'exit_breakdown' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'run_id');
    }

    /**
     * Whether the backtest is good enough for this strategy to count towards Top Picks.
     *
     * Expectancy is the gate that matters: it is the average R the strategy returns per
     * trade after costs, so a strategy can clear the win-rate bar and still be rejected
     * here for losing more on its losers than it makes on its winners. Win rate is kept
     * as a secondary filter, not the decision.
     */
    public function qualifies(): bool
    {
        if ($this->trades < config('screener.min_backtest_trades')) {
            return false;
        }

        // A run from before trades were recorded has no expectancy to judge
        if ($this->expectancy_r !== null && $this->expectancy_r < config('screener.min_expectancy_r')) {
            return false;
        }

        return $this->win_rate >= config('screener.min_win_rate');
    }
}
