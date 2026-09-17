<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * A stock that has genuinely outrun the market and is still being bought.
 *
 * The trap this has to avoid is the one the brief names directly: buying something purely
 * because it has already risen a lot. A large move is both the entry condition and the
 * reason to refuse, depending on where price sits relative to its own average — so a
 * stock stretched far beyond the 20 EMA is reported EXTENDED rather than bought, with the
 * level to wait for.
 */
class MomentumContinuationStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'Momentum Continuation';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.momentum_continuation');
        $closes = $context->closes();
        $close = $context->close();

        $shortReturn = $this->indicators->rateOfChange($closes, $config['short_period']);
        $longReturn = $this->indicators->rateOfChange($closes, $config['long_period']);
        $fast = $this->indicators->ema($closes, 20);
        $slow = $this->indicators->ema($closes, 50);
        $long = $this->indicators->sma($closes, 200);
        $relativeVolume = $context->relativeVolume();

        if ($shortReturn === null || $longReturn === null || ! $fast || ! $slow || ! $long || ! $context->atr) {
            return $this->noSetup($context, [$this->check('History', false, 'Not enough history for a momentum reading')]);
        }

        $ahead = count(array_filter($context->relativeStrength['periods'], fn ($v) => $v !== null && $v > 0));

        $checks = [
            $this->check('Strong recent return', $shortReturn >= $config['min_short_return'],
                sprintf('%+.1f%% over %d days, needs %+.1f%%', $shortReturn, $config['short_period'], $config['min_short_return'])),

            $this->check('Sustained, not one spike', $longReturn > 0,
                sprintf('%+.1f%% over %d days', $longReturn, $config['long_period'])),

            // Rising faster than the index is what separates momentum from a rising tide
            $this->check('Outpacing the market', $context->isLeadingTheMarket(),
                "Ahead of NIFTY over {$ahead} spans"),

            $this->check('Above every average', $close > $fast && $fast > $slow && $close > $long,
                sprintf('Price %.2f, 20 EMA %.2f, 50 EMA %.2f, 200 SMA %.2f', $close, $fast, $slow, $long)),

            $this->check('Still being bought', $relativeVolume !== null && $relativeVolume >= $config['min_relative_volume'],
                sprintf('%.2fx average volume, needs %.1fx', $relativeVolume ?? 0, $config['min_relative_volume'])),
        ];

        // The move has already happened. Entering here takes a different risk to the one
        // the plan describes, so it is named rather than quietly re-priced.
        $extension = ($close - $fast) / $context->atr;

        if ($extension > $config['max_extension_atr']) {
            return new SwingSignal($this->name(), $context->symbol, SwingSignal::EXTENDED, $checks,
                watchFor: sprintf('Price is %.1f ATR above its 20 EMA. Wait for a pullback towards %.2f.', $extension, $fast));
        }

        if (! $this->allPassed($checks)) {
            return $this->noSetup($context, $checks);
        }

        $trigger = $context->bar()['high'];
        $stop = $this->stopFor($context, 'BUY', $trigger);

        return $stop === null
            ? $this->noSetup($context, $checks)
            : $this->ready($context, 'BUY', $trigger, $stop, config('swing.risk.stop_method'), $checks, entryTrigger: $trigger);
    }
}
