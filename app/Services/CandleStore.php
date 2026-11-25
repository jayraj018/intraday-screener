<?php

namespace App\Services;

use App\Models\Candle;
use Illuminate\Support\Carbon;

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

    /** Minutes after the close before the provider's bar is treated as final; its feed lags. */
    public const SETTLE_MINUTES = 30;

    /** A stored close moving by more than this on a finished bar means prices were rescaled. */
    protected const RESCALED = 0.01;

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

    /**
     * Stored candles, downloading them first if this symbol has never been stored.
     *
     * A read-through, so a scan works on a fresh database without a separate backfill step
     * — the first run is slow, every run after it is not.
     */
    public function remember(string $symbol, string $interval = '1d', string $range = '5y'): array
    {
        if (! $this->latestDate($symbol, $interval)) {
            $this->sync($symbol, $interval, $range);
        }

        return $this->get($symbol, $interval);
    }

    /**
     * Stored candles, brought up to date first.
     *
     * A symbol never stored is downloaded in full; one already stored has only its recent
     * tail refreshed. An ad-hoc lookup has to reflect the latest close, but re-downloading
     * five years to learn what happened yesterday would make every search take seconds.
     */
    public function freshen(string $symbol, string $interval = '1d', string $fullRange = '2y', string $tailRange = '3mo'): array
    {
        $stored = $this->latestDate($symbol, $interval);
        $before = $stored ? $this->closesByDate($symbol, $interval) : [];

        $this->sync($symbol, $interval, $stored ? $tailRange : $fullRange);

        if ($stored && $this->wasRescaled($before, $this->closesByDate($symbol, $interval))) {
            $this->sync($symbol, $interval, $this->fullRangeFor($symbol, $interval, $fullRange));
        }

        return $this->get($symbol, $interval);
    }

    /**
     * Candles guaranteed to include the last finished session, or an empty array when they
     * could not be brought up to date.
     *
     * remember() never looks at a symbol again once it is stored, so a daily scan built on
     * it repeats the first scan's prices for ever — including a bar downloaded half way
     * through a session, which looks complete from the next day on. Returning nothing on
     * failure is deliberate: a signal on old prices is worse than no signal.
     */
    public function current(string $symbol, string $interval = '1d', string $fullRange = '2y'): array
    {
        if (! $this->isCurrent($symbol, $interval)) {
            $this->freshen($symbol, $interval, $fullRange);

            if (! $this->isCurrent($symbol, $interval)) {
                return [];
            }
        }

        return $this->get($symbol, $interval);
    }

    /** Downloaded since the last session (or week) closed and settled? */
    public function isCurrent(string $symbol, string $interval = '1d'): bool
    {
        $fetched = Candle::query()
            ->where('symbol', strtoupper($symbol))
            ->where('interval', $interval)
            ->max('fetched_at');

        return $fetched !== null && Carbon::parse($fetched, 'UTC')->gte($this->lastSettled($interval));
    }

    protected function lastSettled(string $interval): Carbon
    {
        $now = now('Asia/Kolkata');
        $settled = $now->copy()->setTimeFromTimeString(config('screener.market_close'))->addMinutes(self::SETTLE_MINUTES);

        if ($settled->gt($now)) {
            $settled->subDay();
        }

        while ($interval === '1wk' ? ! $settled->isFriday() : $settled->isWeekend()) {
            $settled->subDay();
        }

        return $settled;
    }

    /** @return array<string, float> */
    protected function closesByDate(string $symbol, string $interval): array
    {
        return Candle::query()
            ->where('symbol', strtoupper($symbol))
            ->where('interval', $interval)
            ->orderBy('date')
            ->toBase()
            ->pluck('close', 'date')
            ->map(fn ($close) => (float) $close)
            ->all();
    }

    /**
     * A split or bonus rescales every past price. Refreshing only the tail would leave the
     * older bars on the previous scale, and the join would read as an overnight crash.
     *
     * The newest stored bar is ignored: it may simply have been unfinished when stored.
     */
    protected function wasRescaled(array $before, array $after): bool
    {
        array_pop($before);

        foreach ($before as $date => $close) {
            if ($close > 0 && isset($after[$date]) && abs($after[$date] / $close - 1) > self::RESCALED) {
                return true;
            }
        }

        return false;
    }

    /** Enough range to replace everything stored, so no bar is left on the old scale. */
    protected function fullRangeFor(string $symbol, string $interval, string $fullRange): string
    {
        $first = Candle::query()
            ->where('symbol', strtoupper($symbol))
            ->where('interval', $interval)
            ->min('date');

        return $first && Carbon::parse($first)->lt(now()->subMonths(26)) ? '5y' : $fullRange;
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
