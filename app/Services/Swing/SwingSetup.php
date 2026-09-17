<?php

namespace App\Services\Swing;

/**
 * A swing trade plan, as a strategy produces it.
 *
 * The levels are absolute prices, not distances. A swing stop is structural — it sits
 * below a swing low or an EMA that the idea depends on — and that level does not move
 * because the fill came in a rupee higher than the signal. The intraday setups work the
 * other way round, sizing the stop as a multiple of ATR from wherever the fill landed.
 */
final class SwingSetup
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $strategy,
        public readonly string $direction,   // BUY | SELL
        public readonly string $signalDate,
        public readonly float $signalPrice,
        public readonly float $stop,
        public readonly string $stopMethod,  // swing_low | atr | structure_atr | ema
        public readonly float $target1,
        public readonly ?float $target2 = null,
        public readonly array $reasons = [],

        /**
         * A resting order level. Several of these setups are only valid if price actually
         * goes on to prove them — "buy the break above the confirmation candle's high"
         * means nothing if price never gets there. Null enters at the next open instead.
         */
        public readonly ?float $entryTrigger = null,
    ) {
    }

    /** The price the plan is measured from: the trigger if there is one, else the signal. */
    public function referencePrice(): float
    {
        return $this->entryTrigger ?? $this->signalPrice;
    }

    public function isBuy(): bool
    {
        return $this->direction === 'BUY';
    }

    /** Distance from the signal price to the stop — the plan's risk, before any fill. */
    public function plannedRisk(): float
    {
        return abs($this->referencePrice() - $this->stop);
    }

    /**
     * Where a target sits as a multiple of the planned risk. This is the only place an R
     * label is derived, so a target can never be displayed with the wrong one.
     */
    public function rMultiple(float $target): ?float
    {
        $risk = $this->plannedRisk();

        return $risk > 0 ? round(abs($target - $this->referencePrice()) / $risk, 2) : null;
    }
}
