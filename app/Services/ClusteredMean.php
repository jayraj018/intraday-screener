<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * 95% ranges that treat each week as one observation. Trades opened in the same week rise
 * and fall together, so counting them separately would make the range far narrower than the
 * evidence is.
 */
class ClusteredMean
{
    /**
     * @param Collection<string, Collection<int, float>> $weeks results by week
     * @return ?array{0: float, 1: float} null with fewer than $minWeeks
     */
    public static function range(Collection $weeks, int $minWeeks): ?array
    {
        $all = $weeks->flatten();

        return self::around($all->avg(), self::pulls($weeks), $minWeeks);
    }

    /**
     * Range of (average of $a − average of $b). Both are measured in the same weeks, so a
     * good week for the market lifts both and cancels out.
     */
    public static function differenceRange(Collection $a, Collection $b, int $minWeeks): ?array
    {
        if ($a->count() < $minWeeks || $b->count() < $minWeeks) {
            return null;
        }

        $pulls = self::pulls($a);

        foreach (self::pulls($b) as $week => $pull) {
            $pulls[$week] = ($pulls[$week] ?? 0) - $pull;
        }

        return self::around($a->flatten()->avg() - $b->flatten()->avg(), $pulls, $minWeeks);
    }

    /** How far each week pulls the average away from itself. */
    protected static function pulls(Collection $weeks): array
    {
        $all = $weeks->flatten();
        $mean = $all->avg();

        return $weeks->map(fn ($results) => $results->sum(fn ($r) => $r - $mean) / $all->count())->all();
    }

    protected static function around(?float $mean, array $pulls, int $minWeeks): ?array
    {
        $weeks = count($pulls);

        if ($mean === null || $weeks < max(2, $minWeeks)) {
            return null;
        }

        $error = sqrt(array_sum(array_map(fn ($p) => $p ** 2, $pulls)) * $weeks / ($weeks - 1));

        return [round($mean - 1.96 * $error, 3), round($mean + 1.96 * $error, 3)];
    }
}
