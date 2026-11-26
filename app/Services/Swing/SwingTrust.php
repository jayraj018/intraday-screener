<?php

namespace App\Services\Swing;

use Illuminate\Support\Collection;

/**
 * Which strategies the system stands behind, and in what order — see `swing.trust`.
 *
 * One place, so the dashboard, the single-stock check and the paper account cannot come
 * to disagree about which plan comes first.
 */
class SwingTrust
{
    public static function isWatchOnly(string $strategy): bool
    {
        return in_array($strategy, config('swing.trust.watch_only', []), true);
    }

    /** Position in `swing.trust.order`; anything unlisted comes last. */
    public static function position(string $strategy): int
    {
        $position = array_search($strategy, config('swing.trust.order', []), true);

        return $position === false ? PHP_INT_MAX : $position;
    }

    public static function preferred(): ?string
    {
        return config('swing.trust.order')[0] ?? null;
    }

    public static function family(string $strategy): string
    {
        return config("swing.agreement.families.{$strategy}", $strategy);
    }

    /**
     * How one stock ranks against the others, highest first: the most trusted strategy
     * firing, then enough independent families, then how many. Deliberately not the score,
     * which picked no better than chance. Ties keep the order they came in.
     *
     * @param  iterable<int, string>  $strategies  the trading (not watch-only) strategies that fired on the stock
     */
    public static function rank(iterable $strategies): array
    {
        $strategies = collect($strategies);
        $families = $strategies->map(fn ($s) => self::family($s))->unique()->count();

        return [
            $strategies->contains(self::preferred()) ? 1 : 0,
            $families >= config('swing.agreement.min_families_agree') ? 1 : 0,
            $families,
        ];
    }

    /**
     * Most trusted first, watch-only after everything else.
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): string  $strategyOf
     * @return Collection<int, T>
     */
    public static function sort(Collection $items, callable $strategyOf): Collection
    {
        return $items->sortBy(fn ($item) => [
            self::isWatchOnly($strategyOf($item)) ? 1 : 0,
            self::position($strategyOf($item)),
        ])->values();
    }
}
