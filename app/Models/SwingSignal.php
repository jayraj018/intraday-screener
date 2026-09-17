<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored swing signal.
 *
 * Note the namespace: App\Services\Swing\SwingSignal is the value a strategy returns,
 * this is the row it is saved as. They are deliberately separate — the strategy's verdict
 * carries objects and closures, the row carries only what a dashboard needs to read back.
 */
class SwingSignal extends Model
{
    protected $fillable = [
        'scan_date', 'symbol', 'strategy', 'state', 'direction', 'score',
        'signal_price', 'entry_trigger', 'stop_price', 'stop_method',
        'target1', 'target2', 'target1_r', 'target2_r',
        'holding_estimate', 'watch_for', 'checks', 'score_groups',
        'regime_trend', 'regime_volatility', 'relative_strength',
    ];

    protected $casts = [
        'scan_date' => 'date',
        'score' => 'integer',
        'signal_price' => 'float',
        'entry_trigger' => 'float',
        'stop_price' => 'float',
        'target1' => 'float',
        'target2' => 'float',
        'target1_r' => 'float',
        'target2_r' => 'float',
        'checks' => 'array',
        'score_groups' => 'array',
        'relative_strength' => 'array',
    ];

    public function scopeForDate($query, $date)
    {
        return $query->where('scan_date', $date);
    }

    public function scopeActionable($query)
    {
        return $query->where('state', 'READY');
    }

    /** The price a resting order would be placed at, or the signal price if there is none. */
    public function entryLevel(): float
    {
        return $this->entry_trigger ?? $this->signal_price;
    }

    public function riskPerShare(): ?float
    {
        return $this->stop_price === null ? null : abs($this->entryLevel() - $this->stop_price);
    }

    public function riskPercent(): ?float
    {
        $risk = $this->riskPerShare();

        return $risk === null || $this->entryLevel() <= 0 ? null : round($risk / $this->entryLevel() * 100, 2);
    }
}
