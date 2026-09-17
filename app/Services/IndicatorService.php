<?php

namespace App\Services;

class IndicatorService
{
    /**
     * Simple moving average of the last $period closing prices.
     */
    public function sma(array $closes, int $period): ?float
    {
        if (count($closes) < $period) {
            return null;
        }

        $slice = array_slice($closes, -$period);

        return array_sum($slice) / $period;
    }

    /**
     * Exponential moving average.
     */
    public function ema(array $closes, int $period): ?float
    {
        $emas = $this->emaArray($closes, $period);
        return $emas ? end($emas) : null;
    }

    /**
     * Array of EMAs.
     */
    public function emaArray(array $closes, int $period): array
    {
        if (count($closes) < $period) {
            return [];
        }

        $emas = [];
        $k = 2 / ($period + 1);

        $firstSma = array_sum(array_slice($closes, 0, $period)) / $period;
        $emas[$period - 1] = $firstSma;

        for ($i = $period; $i < count($closes); $i++) {
            $emas[$i] = ($closes[$i] * $k) + ($emas[$i - 1] * (1 - $k));
        }

        return $emas;
    }

    /**
     * Relative Strength Index.
     */
    public function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($closes); $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gains[] = $diff > 0 ? $diff : 0;
            $losses[] = $diff < 0 ? -$diff : 0;
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    /**
     * Standard Deviation.
     */
    public function standardDeviation(array $values, ?float $mean = null): float
    {
        $n = count($values);
        if ($n === 0) return 0.0;
        if ($mean === null) {
            $mean = array_sum($values) / $n;
        }
        $variance = 0.0;
        foreach ($values as $val) {
            $variance += pow($val - $mean, 2);
        }
        return sqrt($variance / $n);
    }

    /**
     * Bollinger Bands.
     */
    public function bollingerBands(array $closes, int $period = 20, float $multiplier = 2.0): ?array
    {
        if (count($closes) < $period) {
            return null;
        }

        $slice = array_slice($closes, -$period);
        $sma = array_sum($slice) / $period;
        $stdDev = $this->standardDeviation($slice, $sma);

        return [
            'middle' => $sma,
            'upper' => $sma + ($multiplier * $stdDev),
            'lower' => $sma - ($multiplier * $stdDev),
        ];
    }

    /**
     * MACD.
     */
    public function macd(array $closes, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): ?array
    {
        if (count($closes) < $slowPeriod + $signalPeriod) {
            return null;
        }

        $fastEmas = $this->emaArray($closes, $fastPeriod);
        $slowEmas = $this->emaArray($closes, $slowPeriod);

        $macdLine = [];
        foreach ($slowEmas as $i => $slowVal) {
            if (isset($fastEmas[$i])) {
                $macdLine[$i] = $fastEmas[$i] - $slowVal;
            }
        }

        $macdValues = array_values($macdLine);

        if (count($macdValues) < $signalPeriod) {
            return null;
        }

        $signalEmas = $this->emaArray($macdValues, $signalPeriod);

        if (empty($signalEmas)) {
            return null;
        }

        $keys = array_keys($signalEmas);
        $latestKey = end($keys);
        $prevKey = prev($keys);

        if ($prevKey === false) {
            return null;
        }

        return [
            'macd_now' => $macdValues[$latestKey],
            'signal_now' => $signalEmas[$latestKey],
            'macd_prev' => $macdValues[$prevKey],
            'signal_prev' => $signalEmas[$prevKey],
        ];
    }

    /**
     * True Range per candle: the widest of today's range, and today's high or low
     * measured against yesterday's close — so an overnight gap counts as movement.
     *
     * The first candle has no previous close, so the series starts at index 1.
     */
    public function trueRanges(array $candles): array
    {
        $trueRanges = [];

        for ($i = 1; $i < count($candles); $i++) {
            $prevClose = $candles[$i - 1]['close'];

            $trueRanges[] = max(
                $candles[$i]['high'] - $candles[$i]['low'],
                abs($candles[$i]['high'] - $prevClose),
                abs($candles[$i]['low'] - $prevClose)
            );
        }

        return $trueRanges;
    }

    /**
     * Average True Range, Wilder's smoothing — a stock's own recent volatility, used to
     * size stops proportionally instead of a fixed % for every stock.
     *
     * This was a plain average of the last $period true ranges, which is not ATR as any
     * charting platform computes it: a plain average forgets everything older than the
     * window, while Wilder's keeps decaying weight on it. The two disagree by several
     * percent on real data, and since the stop is 1.5 x ATR, that moved every stop.
     *
     * Seeded with a simple average of the first $period ranges, then smoothed:
     *     ATR = (previous ATR x (period - 1) + current TR) / period
     */
    public function atr(array $candles, int $period): ?float
    {
        $trueRanges = $this->trueRanges($candles);

        if (count($trueRanges) < $period) {
            return null;
        }

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
        }

        return $atr;
    }

    /**
     * ATR at every bar it can be computed for, keyed by the candle's own index.
     *
     * Needed wherever volatility is judged against its own history rather than a fixed
     * number: "quiet for this stock" means nothing without the distribution to compare to,
     * and a threshold that suits a ₹100 stock is meaningless for a ₹3,000 one.
     */
    public function atrArray(array $candles, int $period): array
    {
        $trueRanges = $this->trueRanges($candles);

        if (count($trueRanges) < $period) {
            return [];
        }

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        // True range at index i belongs to candle i + 1, since it needs a previous close
        $values = [$period => $atr];

        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$i]) / $period;
            $values[$i + 1] = $atr;
        }

        return $values;
    }

    /**
     * The value below which $percent of the sample sits. Used to ask whether today's
     * volatility is unusual *for this stock*, rather than against an invented constant.
     */
    public function percentile(array $values, float $percent): ?float
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $index = (int) floor(($percent / 100) * (count($values) - 1));

        return $values[max(0, min($index, count($values) - 1))];
    }

    /**
     * Average Directional Index — how strongly a market is trending, in either direction.
     *
     * ADX says nothing about which way. It is the measure a regime filter needs: a
     * trend-following rule fires constantly in a sideways market and loses on the
     * whipsaws, and a low ADX is what identifies that market before the trade is taken.
     *
     * Rule of thumb: below 20 is directionless, above 25 is a genuine trend.
     *
     * @return array{adx: float, plus_di: float, minus_di: float}|null
     */
    public function adx(array $candles, int $period = 14): ?array
    {
        // One bar to seed true range, $period to seed the DI averages, $period more to
        // average DX into ADX
        if (count($candles) < $period * 2 + 1) {
            return null;
        }

        $plusDm = [];
        $minusDm = [];

        for ($i = 1; $i < count($candles); $i++) {
            $upMove = $candles[$i]['high'] - $candles[$i - 1]['high'];
            $downMove = $candles[$i - 1]['low'] - $candles[$i]['low'];

            // Only the larger of the two counts, and only when it is actually positive:
            // an inside bar contributes no directional movement at all.
            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
        }

        $trueRanges = $this->trueRanges($candles);

        $smoothTr = $this->wilderSmooth($trueRanges, $period);
        $smoothPlus = $this->wilderSmooth($plusDm, $period);
        $smoothMinus = $this->wilderSmooth($minusDm, $period);

        $dxs = [];

        foreach ($smoothTr as $i => $tr) {
            if ($tr <= 0) {
                continue;
            }

            $plusDi = 100 * $smoothPlus[$i] / $tr;
            $minusDi = 100 * $smoothMinus[$i] / $tr;
            $sum = $plusDi + $minusDi;

            $dxs[] = $sum > 0 ? 100 * abs($plusDi - $minusDi) / $sum : 0.0;
        }

        if (count($dxs) < $period) {
            return null;
        }

        // ADX is Wilder's smoothing applied a second time, to DX
        $adx = array_sum(array_slice($dxs, 0, $period)) / $period;

        for ($i = $period; $i < count($dxs); $i++) {
            $adx = (($adx * ($period - 1)) + $dxs[$i]) / $period;
        }

        $lastTr = end($smoothTr);

        return [
            'adx' => $adx,
            'plus_di' => $lastTr > 0 ? 100 * end($smoothPlus) / $lastTr : 0.0,
            'minus_di' => $lastTr > 0 ? 100 * end($smoothMinus) / $lastTr : 0.0,
        ];
    }

    /**
     * Wilder's running sum, the form ADX uses: seeded with the first $period values, then
     * each step drops one $period-th of the running total and adds the new value.
     */
    protected function wilderSmooth(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }

        $smoothed = [array_sum(array_slice($values, 0, $period))];

        for ($i = $period; $i < count($values); $i++) {
            $previous = end($smoothed);
            $smoothed[] = $previous - ($previous / $period) + $values[$i];
        }

        return $smoothed;
    }

    /**
     * Average volume over the last $period days.
     */
    public function averageVolume(array $candles, int $period = 20): ?float
    {
        if (count($candles) < $period) {
            return null;
        }

        $slice = array_slice($candles, -$period);
        $volumes = array_column($slice, 'volume');

        return array_sum($volumes) / count($volumes);
    }

    /**
     * Confirmed swing highs — the pivots a resistance level or a higher-high structure
     * is read from. A bar is a swing high when its high is above every high within
     * $lookback bars either side.
     *
     * `confirmed_at` is the index where the pivot could first be known, which is always
     * $lookback bars after the pivot itself. A strategy that treats the pivot as known at
     * its own index is reading the future: those later bars had not printed yet. Callers
     * replaying history must filter on `confirmed_at`, not `index`.
     *
     * @return array<int, array{index: int, price: float, confirmed_at: int}>
     */
    public function swingHighs(array $candles, int $lookback = 2): array
    {
        return $this->pivots($candles, $lookback, 'high', fn ($a, $b) => $a > $b);
    }

    /**
     * Confirmed swing lows — where a structural stop sits, and what a higher-low
     * structure is read from. Same confirmation delay as swingHighs().
     *
     * @return array<int, array{index: int, price: float, confirmed_at: int}>
     */
    public function swingLows(array $candles, int $lookback = 2): array
    {
        return $this->pivots($candles, $lookback, 'low', fn ($a, $b) => $a < $b);
    }

    protected function pivots(array $candles, int $lookback, string $field, callable $beats): array
    {
        $pivots = [];
        $count = count($candles);

        for ($i = $lookback; $i < $count - $lookback; $i++) {
            $value = $candles[$i][$field];
            $isPivot = true;

            for ($j = $i - $lookback; $j <= $i + $lookback; $j++) {
                if ($j !== $i && ! $beats($value, $candles[$j][$field])) {
                    $isPivot = false;
                    break;
                }
            }

            if ($isPivot) {
                $pivots[] = ['index' => $i, 'price' => $value, 'confirmed_at' => $i + $lookback];
            }
        }

        return $pivots;
    }

    /**
     * Bollinger Band Width — the bands' spread as a fraction of the middle band.
     *
     * This is the measure a volatility-contraction setup is built on: a range that has
     * gone quiet shows as a width near its own recent lows, and the trade is the
     * expansion out of it. Scale-free, so a ₹100 stock and a ₹3,000 one compare directly.
     */
    public function bollingerWidth(array $closes, int $period = 20, float $multiplier = 2.0): ?float
    {
        $bands = $this->bollingerBands($closes, $period, $multiplier);

        if (! $bands || $bands['middle'] <= 0) {
            return null;
        }

        return ($bands['upper'] - $bands['lower']) / $bands['middle'];
    }

    /**
     * Percentage change over the last $period bars.
     */
    public function rateOfChange(array $closes, int $period): ?float
    {
        if (count($closes) < $period + 1) {
            return null;
        }

        $past = $closes[count($closes) - 1 - $period];

        return $past > 0 ? (end($closes) - $past) / $past * 100 : null;
    }

    /**
     * Relative strength against a benchmark: the stock's return over $period bars minus
     * the benchmark's over the same span. Positive means it outpaced the index.
     *
     * Both series must end on the same date, which is the caller's job — comparing a
     * stock's last $period bars against a benchmark that stopped a week earlier silently
     * measures two different windows. The indicator can't detect that, so a mismatched
     * length is the one thing it does check.
     */
    public function relativeStrength(array $closes, array $benchmarkCloses, int $period): ?float
    {
        $stock = $this->rateOfChange($closes, $period);
        $benchmark = $this->rateOfChange($benchmarkCloses, $period);

        if ($stock === null || $benchmark === null) {
            return null;
        }

        return $stock - $benchmark;
    }

    /**
     * Volume Weighted Average Price (VWAP) for intraday candles.
     */
    public function vwap(array $candles): array
    {
        $vwapValues = [];
        $cumTypicalVolume = 0.0;
        $cumVolume = 0.0;

        foreach ($candles as $i => $candle) {
            $high = $candle['high'];
            $low = $candle['low'];
            $close = $candle['close'];
            $volume = $candle['volume'];

            $typicalPrice = ($high + $low + $close) / 3;
            $cumTypicalVolume += ($typicalPrice * $volume);
            $cumVolume += $volume;

            $vwapValues[$i] = $cumVolume > 0 ? ($cumTypicalVolume / $cumVolume) : $typicalPrice;
        }

        return $vwapValues;
    }
}
