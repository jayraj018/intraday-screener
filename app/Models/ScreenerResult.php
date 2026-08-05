<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScreenerResult extends Model
{
    protected $fillable = [
        'symbol',
        'strategy',
        'signal',
        'entry',
        'stop_loss',
        'target',
        'volume_surge',
        'confidence',
        'reason',
        'scan_date',
    ];

    protected $casts = [
        'volume_surge' => 'boolean',
        'scan_date' => 'date',
        'entry' => 'float',
        'stop_loss' => 'float',
        'target' => 'float',
    ];
}
