<?php

namespace App\Services\Swing;

/**
 * What a strategy makes of one stock on one day.
 *
 * Most days the answer is "nothing", and the type says so rather than manufacturing a
 * BUY. A stock that is nearly ready is WATCHLIST, not a weak signal — the difference
 * between the two is the difference between a screener you can act on and one that
 * produces five hundred rows a day.
 */
final class SwingSignal
{
    public const READY = 'READY';
    public const WATCHLIST = 'WATCHLIST';
    public const EXTENDED = 'EXTENDED';
    public const NO_SETUP = 'NO_SETUP';

    /**
     * @param  array<int, array{label: string, pass: bool, detail: string}>  $checks
     */
    public function __construct(
        public readonly string $strategy,
        public readonly string $symbol,
        public readonly string $state,
        public readonly array $checks = [],
        public readonly ?SwingSetup $setup = null,
        public readonly ?string $watchFor = null,
    ) {
    }

    public function isActionable(): bool
    {
        return $this->state === self::READY && $this->setup !== null;
    }

    /** The conditions that failed, for a card that explains itself. */
    public function failedChecks(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['pass']));
    }
}
