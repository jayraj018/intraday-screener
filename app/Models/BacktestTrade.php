<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestTrade extends Model
{
    protected $fillable = [
        'run_id', 'system', 'symbol', 'strategy', 'direction',
        'signal_date', 'signal_price', 'entry_date', 'entry_price',
        'exit_date', 'exit_price', 'exit_reason', 'quantity',
        'gross_pnl', 'costs', 'net_pnl', 'r_multiple', 'bars_held',
    ];

    protected $casts = [
        'signal_date' => 'date',
        'entry_date' => 'date',
        'exit_date' => 'date',
        'signal_price' => 'float',
        'entry_price' => 'float',
        'exit_price' => 'float',
        'gross_pnl' => 'float',
        'costs' => 'float',
        'net_pnl' => 'float',
        'r_multiple' => 'float',
        'quantity' => 'integer',
        'bars_held' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BacktestRun::class, 'run_id');
    }
}
