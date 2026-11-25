<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One stock's 5-minute candles for one day, in the shape the indicators read. */
class IntradayBars extends Model
{
    protected $table = 'intraday_bars';

    protected $guarded = [];

    protected $casts = ['count' => 'integer'];

    public static function pack(array $candles): string
    {
        $rows = array_map(fn ($c) => [
            $c['timestamp'], round($c['open'], 2), round($c['high'], 2), round($c['low'], 2), round($c['close'], 2), (int) $c['volume'],
        ], $candles);

        return base64_encode(gzcompress(json_encode($rows), 9));
    }

    public function candles(): array
    {
        return array_map(
            fn ($r) => array_combine(['timestamp', 'open', 'high', 'low', 'close', 'volume'], $r),
            json_decode(gzuncompress(base64_decode($this->data)), true),
        );
    }
}
