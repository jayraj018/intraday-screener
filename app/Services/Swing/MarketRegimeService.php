<?php

namespace App\Services\Swing;

use App\Services\IndicatorService;

/**
 * What kind of market this is, read from the benchmark.
 *
 * Reported as two separate readings rather than one label. Direction and volatility are
 * independent — a market can trend hard and quietly, or go nowhere violently — and
 * collapsing them into a single word throws away the half a strategy needs. A trend
 * filter wants the direction; a position sizer wants the volatility.
 *
 * Only the benchmark's own candles are read, so a caller replaying history passes the
 * series up to the bar being judged and cannot leak anything later.
 */
class MarketRegimeService
{
    public const UNKNOWN = 'UNKNOWN';

    public function __construct(protected IndicatorService $indicators)
    {
    }

    /**
     * @param  array  $benchmarkCandles  daily candles up to and including the decision bar
     * @return array{trend: string, volatility: string, state: string, adx: ?float, atr_percent: ?float, breadth: string}
     */
    public function detect(array $benchmarkCandles): array
    {
        $config = config('swing.regime');

        return [
            ...$this->trend($benchmarkCandles, $config),
            ...$this->volatility($benchmarkCandles, $config),

            // Advance/decline data is not available from either source this app uses, and
            // a regime call that quietly assumed it would be worse than one that says so.
            'breadth' => 'NOT_AVAILABLE',
        ];
    }

    protected function trend(array $candles, array $config): array
    {
        $adx = $this->indicators->adx($candles, $config['adx_period']);
        $longMa = $this->indicators->sma(array_column($candles, 'close'), $config['trend_ma_period']);

        if (! $adx) {
            return ['trend' => self::UNKNOWN, 'state' => self::UNKNOWN, 'adx' => null];
        }

        // ADX measures how hard a market is moving, not which way; the DI lines and the
        // long moving average supply the direction.
        $trending = $adx['adx'] >= $config['adx_trend_threshold'];
        $up = $adx['plus_di'] > $adx['minus_di'];
        $close = end($candles)['close'];

        $trend = match (true) {
            ! $trending => 'SIDEWAYS',
            $up && ($longMa === null || $close >= $longMa) => 'BULLISH_TREND',
            ! $up && ($longMa === null || $close <= $longMa) => 'BEARISH_TREND',

            // ADX is high but price and momentum disagree with the long trend — a
            // counter-trend move inside a bigger one. Not a trend to follow.
            default => 'SIDEWAYS',
        };

        return ['trend' => $trend, 'state' => $trend, 'adx' => round($adx['adx'], 2)];
    }

    protected function volatility(array $candles, array $config): array
    {
        $atrs = $this->indicators->atrArray($candles, $config['adx_period']);

        if (! $atrs) {
            return ['volatility' => self::UNKNOWN, 'atr_percent' => null];
        }

        // As a percentage of price, so the comparison holds as the index level changes
        $percents = [];

        foreach ($atrs as $index => $atr) {
            $close = $candles[$index]['close'] ?? null;

            if ($close > 0) {
                $percents[$index] = $atr / $close * 100;
            }
        }

        $recent = array_slice($percents, -$config['volatility_lookback'], null, true);
        $current = end($percents);

        if (count($recent) < 20) {
            return ['volatility' => self::UNKNOWN, 'atr_percent' => round($current, 3)];
        }

        $high = $this->indicators->percentile(array_values($recent), $config['high_volatility_percentile']);
        $low = $this->indicators->percentile(array_values($recent), $config['low_volatility_percentile']);

        return [
            'volatility' => match (true) {
                $current >= $high => 'HIGH_VOLATILITY',
                $current <= $low => 'LOW_VOLATILITY',
                default => 'NORMAL',
            },
            'atr_percent' => round($current, 3),
        ];
    }
}
