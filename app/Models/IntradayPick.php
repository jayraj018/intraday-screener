<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntradayPick extends Model
{
    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'close' => 'float',
        'return_60' => 'float',
        'return_3' => 'float',
        'announced' => 'boolean',
    ];

    public function reason(): string
    {
        return sprintf('strong stock (%+.0f%% in 60 days) after a small dip (%.1f%% in 3 days)', $this->return_60, $this->return_3);
    }
}
