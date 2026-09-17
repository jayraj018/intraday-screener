<?php

namespace App\Services\Swing;

/**
 * How a stock has performed against the benchmark.
 *
 * Positive means it outpaced the index over that span. Several spans are reported because
 * none of them is known to be the right one — a stock can lead over 20 days and lag over
 * 100. Which span matters, if any, is a question for walk-forward validation.
 */
class RelativeStrengthService
{
    /**
     * @param  array  $stock  daily candles up to the decision bar
     * @param  array  $benchmark  the benchmark's candles over the same history
     * @return array{periods: array<int, ?float>, leading: bool, aligned_bars: int}
     */
    public function compare(array $stock, array $benchmark, ?array $periods = null): array
    {
        $periods ??= config('swing.relative_strength.periods');

        // Line the two series up by DATE before comparing anything. A stock that missed a
        // session, listed late, or was halted has fewer bars than the index, and comparing
        // by position would silently measure two different windows against each other —
        // producing a number that looks reasonable and means nothing.
        [$stockCloses, $benchmarkCloses] = $this->align($stock, $benchmark);

        $result = [];

        foreach ($periods as $period) {
            $result[$period] = $this->returnOver($stockCloses, $period) === null || $this->returnOver($benchmarkCloses, $period) === null
                ? null
                : round($this->returnOver($stockCloses, $period) - $this->returnOver($benchmarkCloses, $period), 2);
        }

        $measured = array_filter($result, fn ($v) => $v !== null);

        return [
            'periods' => $result,
            // Leading only when it outpaced the index over every span that could be
            // measured — one strong month is not relative strength.
            'leading' => $measured !== [] && ! in_array(true, array_map(fn ($v) => $v <= 0, $measured), true),
            'aligned_bars' => count($stockCloses),
        ];
    }

    /**
     * Closes for the dates both series share, oldest first.
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    protected function align(array $stock, array $benchmark): array
    {
        $benchmarkByDate = array_column($benchmark, 'close', 'date');

        $stockCloses = [];
        $benchmarkCloses = [];

        foreach ($stock as $candle) {
            if (isset($benchmarkByDate[$candle['date']])) {
                $stockCloses[] = $candle['close'];
                $benchmarkCloses[] = $benchmarkByDate[$candle['date']];
            }
        }

        return [$stockCloses, $benchmarkCloses];
    }

    protected function returnOver(array $closes, int $period): ?float
    {
        if (count($closes) < $period + 1) {
            return null;
        }

        $past = $closes[count($closes) - 1 - $period];

        return $past > 0 ? (end($closes) - $past) / $past * 100 : null;
    }
}
