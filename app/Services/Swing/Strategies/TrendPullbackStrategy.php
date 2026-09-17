<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * An established uptrend that has pulled back to its fast average and shown a sign of
 * turning back up.
 *
 * The rule your brief is emphatic about: touching an EMA is not a reason to buy. A
 * pullback is only tradeable once price has stopped falling, so this needs a bullish
 * confirmation bar — one that closes in the upper half of its own range and above the
 * previous bar's close — and then enters on a break of that bar's high. Price has to
 * prove the turn; the setup does not assume it.
 */
class TrendPullbackStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'Trend Pullback';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.trend_pullback');
        $closes = $context->closes();
        $daily = $context->daily;
        $bar = $context->bar();
        $previous = $daily[count($daily) - 2] ?? null;

        $fast = $this->indicators->emaArray($closes, $config['fast']);
        $slow = $this->indicators->emaArray($closes, $config['slow']);
        $rsi = $this->indicators->rsi($closes);

        if (! $fast || ! $slow || ! $previous || ! $context->atr || $rsi === null) {
            return $this->noSetup($context, [$this->check('History', false, 'Not enough history for the pullback test')]);
        }

        $fastNow = $fast[array_key_last($fast)];
        $slowNow = $slow[array_key_last($slow)];
        $range = $bar['high'] - $bar['low'];

        // A bar that closed strongly off its own low, above yesterday — the turn, not the fall
        $closedStrong = $range > 0 && ($bar['close'] - $bar['low']) / $range >= 0.5;
        $confirmation = $closedStrong && $bar['close'] > $previous['close'];

        $pullbackDistance = abs($context->close() - $fastNow);
        $nearAverage = $pullbackDistance <= $context->atr * $config['max_pullback_atr'];

        $checks = [
            $this->check('Uptrend intact', $fastNow > $slowNow && $this->isRising($slow, 10),
                sprintf('%d EMA %.2f above a rising %d EMA %.2f', $config['fast'], $fastNow, $config['slow'], $slowNow)),

            $this->check('Pulled back to support', $nearAverage,
                sprintf('%.2f from the %d EMA, within %.2f', $pullbackDistance, $config['fast'], $context->atr * $config['max_pullback_atr'])),

            // A pullback, not a collapse — and not something already overbought
            $this->check('RSI in the pullback zone', $rsi >= $config['rsi_floor'] && $rsi <= $config['rsi_ceiling'],
                sprintf('RSI %.1f, wanted %d-%d', $rsi, $config['rsi_floor'], $config['rsi_ceiling'])),

            $this->check('Bullish confirmation bar', $confirmation,
                $confirmation
                    ? sprintf('Closed %.2f, in the upper half of its range and above yesterday', $bar['close'])
                    : 'No bar has closed strongly off the low yet'),

            $this->check('Leading the market', $context->isLeadingTheMarket(),
                'Relative strength vs NIFTY: ' . implode(', ', array_map(
                    fn ($p, $v) => "{$p}d " . ($v === null ? 'n/a' : sprintf('%+.1f%%', $v)),
                    array_keys($context->relativeStrength['periods']),
                    $context->relativeStrength['periods'],
                ))),
        ];

        if (! $this->allPassed($checks)) {
            // In a good trend, sitting at support without the turn yet is the textbook
            // watchlist case: everything is in place except the proof.
            $structureHolds = $fastNow > $slowNow && $nearAverage;

            if ($structureHolds && ! $confirmation) {
                return $this->watchlist($context, $checks,
                    'At support in an intact uptrend. Waiting for a bar that closes strongly off its low.');
            }

            return $this->noSetup($context, $checks);
        }

        // Entry on a break of the confirmation bar's high — price must carry on
        $trigger = $bar['high'];
        $stop = $this->stopFor($context, 'BUY', $trigger);

        if ($stop === null) {
            return $this->noSetup($context, $checks);
        }

        return $this->ready($context, 'BUY', $trigger, $stop, config('swing.risk.stop_method'), $checks, entryTrigger: $trigger);
    }
}
