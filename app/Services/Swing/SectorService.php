<?php

namespace App\Services\Swing;

use App\Services\NseService;
use Illuminate\Support\Facades\Cache;

/**
 * Which sector each stock is in, for the per-sector limit (swing.portfolio.max_per_sector).
 *
 * Fetched from NSE only by the nightly scan (refresh) and read from the cache everywhere
 * else, so opening the dashboard never waits on NSE. A stock with no known sector is not
 * limited: guessing one would refuse trades for a reason that may not be true.
 */
class SectorService
{
    public const CACHE_KEY = 'swing.sectors';

    public function __construct(protected NseService $nse)
    {
    }

    /** @return int how many stocks now have a sector */
    public function refresh(): int
    {
        $map = $this->nse->indexIndustries(config('screener.universe_index'));

        // An empty answer is a failed download, not a market with no sectors
        if ($map) {
            Cache::forever(self::CACHE_KEY, $map);
        }

        return count($this->map());
    }

    public function of(string $symbol): ?string
    {
        return $this->map()[strtoupper($symbol)] ?? null;
    }

    /** @return array<string, string> */
    public function map(): array
    {
        return Cache::get(self::CACHE_KEY, []);
    }
}
