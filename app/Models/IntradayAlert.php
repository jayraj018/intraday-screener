<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntradayAlert extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entry' => 'float',
        'max_entry' => 'float',
        'stop_loss' => 'float',
        'target' => 'float',
        'strategies' => 'array',
        'tested' => 'boolean',
        'candle_at' => 'integer',
        'seen_low' => 'float',
        'seen_high' => 'float',
        'exit_price' => 'float',
        'net_pnl' => 'float',
        'r_multiple' => 'float',
        'exit_alerted' => 'boolean',
    ];
}
