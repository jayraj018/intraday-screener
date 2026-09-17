<?php

namespace App\Services\Swing;

/**
 * One swing strategy.
 *
 * A strategy receives a finished context and returns a verdict. It is handed no way to
 * fetch data, which is what makes look-ahead impossible rather than merely discouraged:
 * the series in the context already ends at the bar being judged.
 */
interface SwingStrategyInterface
{
    /** Stored on every signal and trade, so results can be attributed. */
    public function name(): string;

    public function evaluate(SwingContext $context): SwingSignal;
}
