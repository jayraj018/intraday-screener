<?php

namespace App\Services\Research;

use App\Models\NseDailyBar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official NSE end-of-day file for every security: OHLC, volume, turnover and delivery.
 *
 * On a holiday NSE serves the previous session's file under the holiday's name, so the date
 * stored is the one INSIDE the file, never the one asked for. A file that cannot be read is
 * reported as missing; nothing is estimated in its place.
 */
class NseBhavcopyService
{
    protected array $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
        'Accept' => 'text/csv,text/plain,*/*',
    ];

    /**
     * @return array{date: ?string, rows: int, status: string}
     */
    public function import(CarbonInterface $day): array
    {
        $url = sprintf(config('research.bhavcopy_url'), $day->format('dmY'));
        $response = Http::withHeaders($this->headers)->timeout(30)->retry(2, 800, throw: false)->get($url);

        if (! $response->successful() || strlen($response->body()) < 500) {
            return ['date' => null, 'rows' => 0, 'status' => $response->status() === 404 ? 'not_published' : 'unreadable'];
        }

        $rows = $this->parse($response->body());

        if (! $rows) {
            return ['date' => null, 'rows' => 0, 'status' => 'unreadable'];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            NseDailyBar::upsert($chunk, ['symbol', 'date'], [
                'series', 'prev_close', 'open', 'high', 'low', 'close', 'volume', 'turnover', 'deliv_qty', 'deliv_pct',
            ]);
        }

        return ['date' => $rows[0]['date'], 'rows' => count($rows), 'status' => 'ok'];
    }

    /** Main-board equity rows only (EQ, BE, BZ). */
    public function parse(string $csv): array
    {
        $lines = preg_split('/\r?\n/', trim($csv));
        $head = array_map('trim', str_getcsv(array_shift($lines), ',', '"', '\\'));
        $col = array_flip($head);

        foreach (['SYMBOL', 'SERIES', 'DATE1', 'OPEN_PRICE', 'CLOSE_PRICE', 'DELIV_PER'] as $required) {
            if (! isset($col[$required])) {
                Log::warning("NseBhavcopyService: column {$required} missing");

                return [];
            }
        }

        $num = fn ($v) => ($v = trim((string) $v)) === '' || $v === '-' ? null : (float) $v;
        $out = [];

        foreach ($lines as $line) {
            $f = array_map('trim', str_getcsv($line, ',', '"', '\\'));

            if (count($f) < count($head) || ! in_array($f[$col['SERIES']], ['EQ', 'BE', 'BZ'], true)) {
                continue;
            }

            $out[] = [
                'date' => Carbon::createFromFormat('d-M-Y', $f[$col['DATE1']])->toDateString(),
                'symbol' => $f[$col['SYMBOL']],
                'series' => $f[$col['SERIES']],
                'prev_close' => $num($f[$col['PREV_CLOSE']]),
                'open' => $num($f[$col['OPEN_PRICE']]),
                'high' => $num($f[$col['HIGH_PRICE']]),
                'low' => $num($f[$col['LOW_PRICE']]),
                'close' => $num($f[$col['CLOSE_PRICE']]),
                'volume' => (int) $num($f[$col['TTL_TRD_QNTY']]),
                'turnover' => ($num($f[$col['TURNOVER_LACS']]) ?? 0) * 100000,
                'deliv_qty' => ($q = $num($f[$col['DELIV_QTY']])) === null ? null : (int) $q,
                'deliv_pct' => $num($f[$col['DELIV_PER']]),
            ];
        }

        return array_values(array_filter($out, fn ($r) => $r['open'] !== null && $r['close'] !== null));
    }

    /**
     * Import weekdays going back from $until until $sessions distinct sessions are stored.
     *
     * @return array<int, array{asked: string, date: ?string, rows: int, status: string}>
     */
    public function backfill(int $sessions, ?CarbonInterface $until = null): array
    {
        $day = ($until ?? now('Asia/Kolkata'))->copy()->startOfDay();
        $limit = $day->toDateString();
        $log = [];
        $tries = 0;

        while (NseDailyBar::where('date', '<=', $limit)->distinct()->count('date') < $sessions && $tries < $sessions * 3) {
            // weekends too: special sessions (Muhurat, Budget day) trade on them; a stale copy dedupes by its date
            if (! NseDailyBar::where('date', $day->toDateString())->exists()) {
                $log[] = ['asked' => $day->toDateString()] + $this->import($day);
                usleep(250000);
            }

            $day->subDay();
            $tries++;
        }

        return $log;
    }
}
