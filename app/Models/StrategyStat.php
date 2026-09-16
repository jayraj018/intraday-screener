<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StrategyStat extends Model
{
    protected $fillable = [
        'strategy',
        'trades',
        'wins',
        'win_rate',
    ];

    protected $casts = [
        'trades' => 'integer',
        'wins' => 'integer',
        'win_rate' => 'float',
    ];

    /**
     * Whether the backtest is good enough for this strategy to count towards Top Picks.
     */
    public function qualifies(): bool
    {
        return $this->trades >= config('screener.min_backtest_trades')
            && $this->win_rate >= config('screener.min_win_rate');
    }
}
