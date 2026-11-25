<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntradayAlert extends Model
{
    public const SIGNAL = 'signal';
    public const RANDOM = 'random';

    protected $guarded = [];

    protected $casts = [
        'entry' => 'float',
        'max_entry' => 'float',
        'stop_loss' => 'float',
        'target' => 'float',
        'quantity' => 'integer',
        'candle_at' => 'integer',
        'seen_low' => 'float',
        'seen_high' => 'float',
        'exit_price' => 'float',
        'net_pnl' => 'float',
        'r_multiple' => 'float',
        'exit_alerted' => 'boolean',
    ];

    public function day(): string
    {
        return substr($this->alert_date, 0, 10);
    }
}
