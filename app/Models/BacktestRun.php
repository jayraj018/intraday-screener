<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BacktestRun extends Model
{
    protected $fillable = ['system', 'range', 'symbols', 'settings', 'started_at', 'finished_at'];

    protected $casts = [
        'settings' => 'array',
        'symbols' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(BacktestTrade::class, 'run_id');
    }
}
