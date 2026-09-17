<?php

namespace App\Services\Swing;

/**
 * One slice of history, with the two dates kept apart that people usually conflate.
 *
 * `from`/`to` bound the days a signal may be TAKEN on. `warmupFrom` is the earliest day
 * the indicators may READ. A 200-day moving average on the window's first tradeable day
 * needs 200 days of prior history — that is warm-up, not look-ahead, and a harness that
 * cannot express the difference either starts every window blind or quietly lets a
 * strategy peek past its own edge.
 */
final class WalkForwardWindow
{
    public function __construct(
        public readonly string $phase,       // train | validate | test
        public readonly int $fold,
        public readonly string $from,        // first date a signal may be taken
        public readonly string $to,          // last date a signal may be taken
        public readonly string $warmupFrom,  // earliest date indicators may read
    ) {
    }

    public function label(): string
    {
        return "fold {$this->fold} {$this->phase} ({$this->from} to {$this->to})";
    }
}
