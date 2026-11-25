<?php

namespace App\Services\Swing;

use App\Services\IndicatorService;
use App\Services\ScreenerService;
use Illuminate\Support\Carbon;

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
        protected ScreenerService $screener,
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
        // Today's daily bar is still forming during the session: its close is the live
        // price and its volume is only what has traded so far. Reading it as finished is
        // what made the intraday setups change price on every refresh, and every series
        // here is dropped to completed bars for the same reason — including the benchmark,
        // so relative strength compares two finished windows.
        $daily = $this->screener->completedDailyCandles($daily);
        $benchmark = $this->screener->completedDailyCandles($benchmark);

        // A 200-day trend filter needs 200 days. Below this the indicators return nulls
        // and a strategy would be judging a stock it cannot actually see.
        if (count($daily) < 60) {
            return null;
        }

        $closes = array_column($daily, 'close');
        $weekly = $this->completedWeeks($weekly, end($daily)['date']);

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
     * Weekly bars that had closed by the decision bar. A week is only a week once it has
     * finished; judging a trend from two days of it reads the noise as the signal.
     *
     * Measured from the decision bar, not from the clock: a weekly bar is dated by its
     * Monday but holds Friday's close, so replaying a past Wednesday against the clock
     * would hand the strategy two days it could not have seen.
     */
    protected function completedWeeks(array $weekly, string $decisionDate): array
    {
        $decision = Carbon::parse($decisionDate);
        $cutoff = $decision->dayOfWeekIso >= 5
            ? $decision->copy()->startOfWeek(Carbon::MONDAY)->addWeek()   // Friday's bar completes its own week
            : $decision->copy()->startOfWeek(Carbon::MONDAY);

        return array_values(array_filter($weekly, fn ($bar) => $bar['date'] < $cutoff->toDateString()));
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
