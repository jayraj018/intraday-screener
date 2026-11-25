<?php

namespace App\Services\Swing;

/**
 * Buys stocks chosen by a hash, with the same stop, targets, liquidity floor and order type
 * as everything else. Never shown as a signal: it exists so that the paper log can answer
 * whether a strategy did better than no strategy at all.
 */
class RandomControlStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'Random Control';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        // Hashed rather than drawn, so running the scan twice picks the same stocks
        $draw = hexdec(substr(md5("{$context->symbol}|{$context->date()}|paper"), 0, 8)) / 0xFFFFFFFF;

        if ($draw >= config('swing.paper.random_rate')) {
            return $this->noSetup($context, []);
        }

        $trigger = $context->bar()['high'];
        $stop = $this->stopFor($context, 'BUY', $trigger);

        return $stop === null
            ? $this->noSetup($context, [])
            : $this->ready($context, 'BUY', $trigger, $stop, config('swing.risk.stop_method'), [], entryTrigger: $trigger);
    }
}
