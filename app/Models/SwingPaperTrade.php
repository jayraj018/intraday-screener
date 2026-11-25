<?php

namespace App\Models;

use App\Services\Swing\SwingSetup;
use Illuminate\Database\Eloquent\Model;

class SwingPaperTrade extends Model
{
    public const PENDING = 'PENDING';     // the entry session has not closed yet
    public const OPEN = 'OPEN';
    public const CLOSED = 'CLOSED';
    public const NO_ENTRY = 'NO_ENTRY';   // the order expired or was refused

    public const SIGNAL = 'signal';
    public const RANDOM = 'random';

    protected $guarded = [];

    protected $casts = [
        'signal_date' => 'date',
        'entry_date' => 'date',
        'exit_date' => 'date',
        'logged_at' => 'datetime',
        'late' => 'boolean',
        'rules' => 'array',
        'signal_price' => 'float',
        'entry_trigger' => 'float',
        'max_entry' => 'float',
        'stop_price' => 'float',
        'target1' => 'float',
        'target2' => 'float',
        'planned_value' => 'float',
        'planned_risk' => 'float',
        'price_factor' => 'float',
        'entry_price' => 'float',
        'exit_price' => 'float',
        'net_pnl' => 'float',
        'r_multiple' => 'float',
        'bars_held' => 'integer',
    ];

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::PENDING, self::OPEN]);
    }

    /** What the paper account is holding, or has an order resting for. */
    public function scopeHeld($query)
    {
        return $query->unresolved()->where('portfolio', 'TAKEN');
    }

    /** The plan as it was logged, on today's price scale. */
    public function toSetup(float $factor = 1.0): SwingSetup
    {
        return new SwingSetup(
            symbol: $this->symbol,
            strategy: $this->strategy,
            direction: $this->direction,
            signalDate: $this->signal_date->toDateString(),
            signalPrice: $this->signal_price * $factor,
            stop: $this->stop_price * $factor,
            stopMethod: $this->stop_method,
            target1: $this->target1 * $factor,
            target2: $this->target2 === null ? null : $this->target2 * $factor,
            entryTrigger: $this->entry_trigger === null ? null : $this->entry_trigger * $factor,
        );
    }
}
