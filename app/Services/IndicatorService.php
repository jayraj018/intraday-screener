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
     * Average True Range — measures a stock's own recent volatility.
     * Used to size stop-losses proportionally instead of a fixed % for every stock.
     */
    public function atr(array $candles, int $period): ?float
    {
        if (count($candles) < $period + 1) {
            return null;
        }

        $trueRanges = [];

        for ($i = 1; $i < count($candles); $i++) {
            $high = $candles[$i]['high'];
            $low = $candles[$i]['low'];
            $prevClose = $candles[$i - 1]['close'];

            $trueRanges[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        $slice = array_slice($trueRanges, -$period);

        return array_sum($slice) / count($slice);
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
