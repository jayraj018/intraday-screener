<?php

namespace App\Services\Swing;

use App\Services\IndicatorService;

/**
 * Assembles the context a strategy is handed.
 *
 * The market regime is computed once for a whole scan and passed in, not recomputed per
 * stock: it is a property of the benchmark, identical for every symbol on a given day,
 * and a walk-forward run evaluates hundreds of symbols across hundreds of bars.
 */
class SwingContextBuilder
{
    public function __construct(
        protected IndicatorService $indicators,
        protected RelativeStrengthService $relativeStrength,
    ) {
    }

    /**
     * @param  array  $daily  candles ending at the decision bar — never beyond it
     * @param  array  $weekly  weekly candles over the same history
     * @param  array  $benchmark  benchmark candles over the same history
     * @param  array  $regime  from MarketRegimeService, computed once per bar for the market
     */
    public function build(string $symbol, array $daily, array $weekly, array $benchmark, array $regime): ?SwingContext
    {
        // A 200-day trend filter needs 200 days. Below this the indicators return nulls
        // and a strategy would be judging a stock it cannot actually see.
        if (count($daily) < 60) {
            return null;
        }

        $closes = array_column($daily, 'close');

        return new SwingContext(
            symbol: $symbol,
            daily: $daily,
            weekly: $weekly,
            regime: $regime,
            relativeStrength: $this->relativeStrength->compare($daily, $benchmark),
            weeklyTrend: $this->weeklyTrend($weekly),
            atr: $this->indicators->atr($daily, config('screener.atr_period')),
            adx: $this->indicators->adx($daily, config('swing.regime.adx_period')),
            bollingerWidth: $this->indicators->bollingerWidth($closes),
            averageVolume: $this->indicators->averageVolume($daily),
        );
    }

    /**
     * The weekly picture a daily setup is taken with or against: price above a rising
     * 20-week EMA, or below a falling one. Anything else is UNKNOWN rather than forced
     * into a direction.
     */
    protected function weeklyTrend(array $weekly): string
    {
        $period = config('swing.weekly.trend_ema_period');
        $closes = array_column($weekly, 'close');
        $emas = $this->indicators->emaArray($closes, $period);

        // Need the EMA and one earlier value to know which way it is pointing
        if (count($emas) < 2) {
            return MarketRegimeService::UNKNOWN;
        }

        $keys = array_keys($emas);
        $now = $emas[end($keys)];
        $earlier = $emas[$keys[max(0, count($keys) - 5)]];
        $close = end($closes);

        return match (true) {
            $close > $now && $now >= $earlier => 'UP',
            $close < $now && $now <= $earlier => 'DOWN',
            default => MarketRegimeService::UNKNOWN,
        };
    }
}
