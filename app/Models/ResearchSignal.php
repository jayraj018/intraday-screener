<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchSignal extends Model
{
    public const PENDING = 'PENDING';
    public const OPEN = 'OPEN';
    public const CLOSED = 'CLOSED';
    public const NO_ENTRY = 'NO_ENTRY';

    protected $guarded = [];

    protected $casts = [
        'signal_date' => 'date:Y-m-d', 'entry_date' => 'date:Y-m-d', 'exit_date' => 'date:Y-m-d', 'logged_at' => 'datetime',
        'late' => 'boolean', 'conditions' => 'array', 'rules' => 'array',
        'signal_close' => 'float', 'atr' => 'float', 'stop_price' => 'float', 'reference_target' => 'float',
        'entry_price' => 'float', 'current_stop' => 'float', 'highest_close' => 'float', 'exit_price' => 'float',
        'net_pct' => 'float', 'r_multiple' => 'float', 'bars_held' => 'integer',
    ];
}
