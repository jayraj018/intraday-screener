<?php

namespace App\Services;

use App\Models\Candle;
use Illuminate\Support\Facades\DB;

/**
 * Local storage for daily and weekly candles.
 *
 * Every scan and backtest used to re-download from the provider, which made results
 * irreproducible — two runs of identical code returned different numbers because of data
 * revisions and symbols dropped to rate-limiting. Walk-forward validation, which replays
 * the same period many times with different parameters, is not viable that way.
 *
 * Reads come back in exactly the array shape IndicatorService and the strategies already
 * expect, so nothing downstream needs to know whether a candle came from the network or
 * from Postgres.
 */
class CandleStore
{
    /** Rows per upsert. Large enough to be fast, small enough to keep the query sane. */
    protected const CHUNK = 500;

    public function __construct(protected StockDataService $data)
    {
    }

    /**
     * Download a symbol and store it, returning how many rows were written.
     *
     * An upsert rather than an insert: re-syncing an overlapping range corrects revised
     * bars in place instead of duplicating them.
     */
    public function sync(string $symbol, string $interval = '1d', string $range = '5y'): int
    {
        $candles = match ($interval) {
            '1d' => $this->data->getDailyCandles($symbol, $range),
            '1wk' => $this->data->getWeeklyCandles($symbol, $range),
            default => throw new \InvalidArgumentException("Only 1d and 1wk are stored, not {$interval}."),
        };

        if (! $candles) {
            return 0;
        }

        $symbol = strtoupper($symbol);
        $now = now();
        $written = 0;

        foreach (array_chunk($candles, self::CHUNK) as $chunk) {
            $rows = array_map(fn ($c) => [
                'symbol' => $symbol,
                'interval' => $interval,
                'date' => $c['date'],
                'open' => $c['open'],
                'high' => $c['high'],
                'low' => $c['low'],
                'close' => $c['close'],
                'adj_close' => $c['adj_close'] ?? null,
                'volume' => $c['volume'],
                'fetched_at' => $now,
            ], $chunk);

            Candle::upsert($rows, ['symbol', 'interval', 'date'], ['open', 'high', 'low', 'close', 'adj_close', 'volume', 'fetched_at']);
            $written += count($rows);
        }

        return $written;
    }

    /**
     * Stored candles, oldest first, in the shape the indicators read.
     *
     * $to is what makes a point-in-time replay possible: asking for everything up to a
     * past date returns what was knowable then, with no chance of a later bar leaking in.
     */
    public function get(string $symbol, string $interval = '1d', ?string $from = null, ?string $to = null): array
    {
        $query = Candle::query()
            ->where('symbol', strtoupper($symbol))
            ->where('interval', $interval)
            ->orderBy('date')
            ->select(['date', 'open', 'high', 'low', 'close', 'adj_close', 'volume']);

        if ($from) {
            $query->where('date', '>=', $from);
        }

        if ($to) {
            $query->where('date', '<=', $to);
        }

        return $query->get()->map(fn (Candle $c) => [
            'date' => $c->date->toDateString(),
            'open' => $c->open,
            'high' => $c->high,
            'low' => $c->low,
            'close' => $c->close,
            'adj_close' => $c->adj_close,
            'volume' => $c->volume,
        ])->all();
    }

    /** The most recent stored date, so a re-sync knows how far behind it is. */
    public function latestDate(string $symbol, string $interval = '1d'): ?string
    {
        return Candle::query()
            ->where('symbol', strtoupper($symbol))
            ->where('interval', $interval)
            ->max('date');
    }

    /**
     * What is stored, per interval: symbols, rows, and the date range covered.
     */
    public function summary(): array
    {
        return Candle::query()
            ->groupBy('interval')
            ->select('interval')
            ->selectRaw('COUNT(DISTINCT symbol) AS symbols')
            ->selectRaw('COUNT(*) AS rows')
            ->selectRaw('MIN(date) AS first_date')
            ->selectRaw('MAX(date) AS last_date')
            ->get()
            ->keyBy('interval')
            ->map(fn ($r) => [
                'symbols' => (int) $r->symbols,
                'rows' => (int) $r->rows,
                'first_date' => $r->first_date,
                'last_date' => $r->last_date,
            ])
            ->all();
    }
}
