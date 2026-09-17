<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Candle extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'symbol', 'interval', 'date',
        'open', 'high', 'low', 'close', 'adj_close', 'volume', 'fetched_at',
    ];

    protected $casts = [
        'date' => 'date',
        'open' => 'float',
        'high' => 'float',
        'low' => 'float',
        'close' => 'float',
        'adj_close' => 'float',
        'volume' => 'integer',
        'fetched_at' => 'datetime',
    ];
}
