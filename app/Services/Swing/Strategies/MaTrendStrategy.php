<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * Price in an established, stacked uptrend, pulled back to the fast average.
 *
 * Deliberately NOT a crossover. A 20/50 cross fires constantly in a sideways market and
 * loses on every whipsaw, which is what the intraday MA Crossover result showed. So the
 * averages here describe a *state* that must already hold, and ADX has to confirm there
 * is a trend at all before any of it counts.
 */
class MaTrendStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'MA Trend Following';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.ma_trend');
        $closes = $context->closes();
        $close = $context->close();

        $fast = $this->indicators->emaArray($closes, $config['fast']);
        $slow = $this->indicators->emaArray($closes, $config['slow']);
        $long = $this->indicators->sma($closes, $config['long']);

        if (! $fast || ! $slow || ! $long || ! $context->atr) {
            return $this->noSetup($context, [$this->check('History', false, 'Not enough history for a 200-day trend')]);
        }

        $fastNow = $fast[array_key_last($fast)];
        $slowNow = $slow[array_key_last($slow)];
        $adx = $context->adx['adx'] ?? 0;

        $checks = [
            $this->check('Stacked averages', $fastNow > $slowNow && $close > $slowNow,
                sprintf('%d EMA %.2f vs %d EMA %.2f', $config['fast'], $fastNow, $config['slow'], $slowNow)),

            $this->check('Above the long trend', $close > $long,
                sprintf('Price %.2f vs %d SMA %.2f', $close, $config['long'], $long)),

            $this->check('Slow average rising', $this->isRising($slow, $config['slope_lookback']),
                sprintf('%d EMA over the last %d bars', $config['slow'], $config['slope_lookback'])),

            // Without this the averages can be stacked while the market goes nowhere
            $this->check('Trend has strength', $adx >= $config['min_adx'],
                sprintf('ADX %.1f, needs %.0f', $adx, $config['min_adx'])),

            $this->check('Market regime', ! $context->marketIsSideways(),
                'NIFTY is ' . ($context->regime['trend'] ?? 'UNKNOWN')),
        ];

        if (! $this->allPassed($checks)) {
            return $this->noSetup($context, $checks);
        }

        // Entry on strength: a break of today's high, so price must confirm the idea
        $trigger = $context->bar()['high'];
        $stop = $this->stopFor($context, 'BUY', $trigger);

        if ($stop === null) {
            return $this->noSetup($context, $checks);
        }

        return $this->ready($context, 'BUY', $trigger, $stop, config('swing.risk.stop_method'), $checks, entryTrigger: $trigger);
    }
}
