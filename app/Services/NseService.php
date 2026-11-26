<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NseService
{
    protected array $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
        'Accept' => 'application/json,text/plain,*/*',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    /**
     * Symbols in an NSE index, e.g. "nifty500" — the list NSE publishes, refreshed daily.
     */
    public function indexSymbols(string $index): array
    {
        return $this->remember("nse.index.{$index}", now()->addDay(), function () use ($index) {
            $response = Http::withHeaders($this->headers)->timeout(20)->retry(2, 500, throw: false)
                ->get("https://nsearchives.nseindia.com/content/indices/ind_{$index}list.csv");

            if (! $response->successful()) {
                return null;
            }

            $lines = preg_split('/\r?\n/', trim($response->body()));
            $column = array_search('Symbol', str_getcsv(array_shift($lines)));

            if ($column === false) {
                return null;
            }

            $symbols = array_values(array_filter(array_map(fn ($line) => trim(str_getcsv($line)[$column] ?? ''), $lines)));

            return $symbols ?: null;
        }) ?? [];
    }

    /**
     * Each stock's industry in an NSE index ("Financial Services", "Capital Goods"...), from
     * the same list as indexSymbols(). Today's classification, not a history.
     *
     * @return array<string, string> symbol => industry
     */
    public function indexIndustries(string $index): array
    {
        return $this->remember("nse.industry.{$index}", now()->addDay(), function () use ($index) {
            $response = Http::withHeaders($this->headers)->timeout(20)->retry(2, 500, throw: false)
                ->get("https://nsearchives.nseindia.com/content/indices/ind_{$index}list.csv");

            if (! $response->successful()) {
                return null;
            }

            $lines = preg_split('/\r?\n/', trim($response->body()));
            $header = str_getcsv(array_shift($lines), escape: '');
            $symbol = array_search('Symbol', $header);
            $industry = array_search('Industry', $header);

            if ($symbol === false || $industry === false) {
                return null;
            }

            $map = [];

            foreach ($lines as $line) {
                $row = str_getcsv($line, escape: '');
                $key = strtoupper(trim($row[$symbol] ?? ''));

                if ($key !== '' && trim($row[$industry] ?? '') !== '') {
                    $map[$key] = trim($row[$industry]);
                }
            }

            return $map ?: null;
        }) ?? [];
    }

    /**
     * Board meetings (results, dividends, fund raising, buybacks...) between two dates,
     * one row per company per day.
     *
     * @return array<int, array{symbol: string, name: string, purpose: string, date: string}>
     */
    public function boardMeetings(CarbonInterface $from, CarbonInterface $to): array
    {
        $key = 'nse.board_meetings.' . $from->toDateString() . '.' . $to->toDateString();

        return $this->remember($key, now()->addHour(), function () use ($from, $to) {
            $response = Http::withHeaders($this->headers)->timeout(20)->retry(2, 500, throw: false)
                ->get('https://www.nseindia.com/api/corporate-board-meetings', [
                    'index' => 'equities',
                    'from_date' => $from->format('d-m-Y'),
                    'to_date' => $to->format('d-m-Y'),
                ]);

            if (! $response->successful() || ! is_array($response->json())) {
                return null;
            }

            return collect($response->json())
                ->groupBy(fn ($row) => $row['bm_symbol'] . '|' . $row['bm_date'])
                ->map(function ($rows) {
                    // NSE posts a generic "Board Meeting Intimation" row next to the one saying what the meeting is for
                    $row = $rows->firstWhere('bm_purpose', '!=', 'Board Meeting Intimation') ?? $rows->first();

                    return [
                        'symbol' => strtoupper($row['bm_symbol']),
                        'name' => $row['sm_name'],
                        'purpose' => $row['bm_purpose'] === 'Board Meeting Intimation' ? 'Board Meeting' : $row['bm_purpose'],
                        'date' => Carbon::createFromFormat('d-M-Y', $row['bm_date'])->toDateString(),
                    ];
                })
                ->values()
                ->all();
        }) ?? [];
    }

    /**
     * What listed companies told the exchange between two dates, or null when it could not be
     * read — which is not the same as there being no news.
     *
     * @return ?array<int, array{symbol: string, category: string, text: string}>
     */
    public function announcements(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $key = 'nse.announcements.' . $from->toDateString() . '.' . $to->toDateString();

        return $this->remember($key, now()->addMinutes(10), function () use ($from, $to) {
            $response = Http::withHeaders($this->headers)->timeout(30)->retry(2, 500, throw: false)
                ->get('https://www.nseindia.com/api/corporate-announcements', [
                    'index' => 'equities',
                    'from_date' => $from->format('d-m-Y'),
                    'to_date' => $to->format('d-m-Y'),
                ]);

            // A day on which no company says anything does not happen: nothing means blocked
            if (! $response->successful() || ! is_array($response->json()) || ! $response->json()) {
                return null;
            }

            return collect($response->json())->map(fn ($row) => [
                'symbol' => strtoupper($row['symbol'] ?? ''),
                'category' => (string) ($row['desc'] ?? ''),
                'text' => mb_substr((string) ($row['attchmntText'] ?? ''), 0, 300),
            ])->all();
        });
    }

    /** Weekends and the exchange's published holidays. An unreadable list counts as open. */
    public function isTradingDay(CarbonInterface $day): bool
    {
        if ($day->isWeekend()) {
            return false;
        }

        $holidays = $this->remember('nse.holidays.' . $day->year, now()->addWeek(), function () {
            $response = Http::withHeaders($this->headers)->timeout(20)->retry(2, 500, throw: false)
                ->get('https://www.nseindia.com/api/holiday-master', ['type' => 'trading']);

            $rows = $response->successful() ? $response->json('CM') : null;

            return $rows
                ? array_map(fn ($row) => Carbon::createFromFormat('d-M-Y', $row['tradingDate'])->toDateString(), $rows)
                : null;
        }) ?? [];

        return ! in_array($day->toDateString(), $holidays, true);
    }

    /**
     * Cache a successful fetch, and keep the last good copy so a refused request (NSE's
     * bot protection blocks one now and then) serves slightly old data instead of nothing.
     */
    protected function remember(string $key, CarbonInterface $expiresAt, callable $fetch): ?array
    {
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        try {
            $fresh = $fetch();
        } catch (\Throwable $e) {
            Log::warning("NseService: exception refreshing {$key} — " . $e->getMessage());
            $fresh = null;
        }

        if ($fresh !== null) {
            Cache::put($key, $fresh, $expiresAt);
            Cache::forever("{$key}.last_good", $fresh);

            return $fresh;
        }

        Log::warning("NseService: couldn't refresh {$key}, using the last good copy if there is one");

        return Cache::get("{$key}.last_good");
    }
}
