<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NseDailyBar extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'prev_close' => 'float', 'open' => 'float', 'high' => 'float', 'low' => 'float', 'close' => 'float',
        'volume' => 'integer', 'turnover' => 'float', 'deliv_qty' => 'integer', 'deliv_pct' => 'float',
    ];
}
