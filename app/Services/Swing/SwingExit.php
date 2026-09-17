<?php

namespace App\Services\Swing;

/**
 * One closing order. A position can be scaled out, so a trade may have more than one.
 */
final class SwingExit
{
    public function __construct(
        public readonly string $date,
        public readonly float $price,
        public readonly int $quantity,
        public readonly string $reason,  // stop | stop_gap | target1 | target2 | target_gap | trailing_stop | trend_invalidated | max_holding_days
    ) {
    }
}
