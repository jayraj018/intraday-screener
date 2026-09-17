<?php

namespace App\Services\Swing;

/**
 * Whether a stock's data can be trusted enough to judge it at all.
 *
 * The distinction this exists to preserve: "we looked and found nothing" and "we could
 * not look" are different answers, and collapsing them into NO_SETUP hides the second.
 * A stock with a broken series should never silently produce a clean-looking signal.
 *
 * Nothing here substitutes a default for missing data. Every failure is named.
 */
class DataQualityService
{
    /**
     * @return array{sufficient: bool, problems: array<int, string>, bars: int, last_date: ?string}
     */
    public function assess(array $daily, array $benchmark, array $weekly = []): array
    {
        $config = config('swing.data_quality');
        $problems = [];

        if (! $daily) {
            return ['sufficient' => false, 'problems' => ['No price history at all'], 'bars' => 0, 'last_date' => null];
        }

        if (count($daily) < $config['min_daily_bars']) {
            $problems[] = sprintf('Only %d daily bars; %d are needed for a 200-day trend', count($daily), $config['min_daily_bars']);
        }

        if (count($benchmark) < $config['min_benchmark_bars']) {
            $problems[] = sprintf('Benchmark has only %d bars; relative strength and market regime cannot be measured', count($benchmark));
        }

        $problems = array_merge($problems, $this->structural($daily), $this->continuity($daily, $config));

        // Weekly context is optional — its absence downgrades the reading rather than
        // disqualifying the stock, so it is reported without failing the assessment.
        $notes = $weekly ? [] : ['No weekly candles; the higher-timeframe trend reads UNKNOWN'];

        return [
            'sufficient' => $problems === [],
            'problems' => array_merge($problems, $notes),
            'blocking' => $problems,
            'bars' => count($daily),
            'last_date' => end($daily)['date'],
        ];
    }

    /** Candles that contradict themselves are corrupt, not merely unusual. */
    protected function structural(array $daily): array
    {
        $problems = [];
        $invalid = 0;
        $zeroVolume = 0;
        $duplicates = 0;
        $seen = [];

        foreach ($daily as $candle) {
            if ($candle['high'] < $candle['low']
                || $candle['close'] > $candle['high'] || $candle['close'] < $candle['low']
                || $candle['open'] > $candle['high'] || $candle['open'] < $candle['low']) {
                $invalid++;
            }

            if (($candle['volume'] ?? 0) <= 0) {
                $zeroVolume++;
            }

            if (isset($seen[$candle['date']])) {
                $duplicates++;
            }

            $seen[$candle['date']] = true;
        }

        if ($invalid > 0) {
            $problems[] = "{$invalid} candles have impossible OHLC relationships";
        }

        if ($duplicates > 0) {
            $problems[] = "{$duplicates} duplicate dates in the series";
        }

        if ($zeroVolume > 0) {
            $problems[] = "{$zeroVolume} candles have no volume";
        }

        return $problems;
    }

    /** A long hole in the series, or a series that stopped updating. */
    protected function continuity(array $daily, array $config): array
    {
        $problems = [];
        $largestGap = 0;
        $gapAt = null;

        for ($i = 1; $i < count($daily); $i++) {
            $gap = (int) round((strtotime($daily[$i]['date']) - strtotime($daily[$i - 1]['date'])) / 86400);

            if ($gap > $largestGap) {
                $largestGap = $gap;
                $gapAt = $daily[$i]['date'];
            }
        }

        // Weekends and holidays make small gaps normal; only a long one means missing data
        if ($largestGap > $config['max_gap_days']) {
            $problems[] = "A {$largestGap}-day hole in the series before {$gapAt}";
        }

        $staleDays = (int) round((time() - strtotime(end($daily)['date'])) / 86400);

        if ($staleDays > $config['max_stale_days']) {
            $problems[] = "Latest bar is {$staleDays} days old; the stock may be suspended or delisted";
        }

        return $problems;
    }
}
