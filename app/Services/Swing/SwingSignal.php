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

    /** The data could not be trusted enough to judge the stock at all. */
    public const INSUFFICIENT_DATA = 'INSUFFICIENT_DATA';

    /** A setup existed but something has since broken it. */
    public const INVALIDATED = 'INVALIDATED';

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

    /**
     * QUALIFIED / NOT_QUALIFIED / INSUFFICIENT_DATA.
     *
     * Kept apart from `state` on purpose. State says what the chart is doing; this says
     * whether the system is willing to stand behind it — which additionally requires
     * historical evidence the strategy has an edge. Today nothing can be QUALIFIED,
     * because no swing backtest has run.
     */
    public function qualification(?array $evidence = null): array
    {
        if ($this->state === self::INSUFFICIENT_DATA) {
            return ['status' => 'INSUFFICIENT_DATA', 'reasons' => array_column($this->failedChecks(), 'detail')];
        }

        if (! $this->isActionable()) {
            return ['status' => 'NOT_QUALIFIED', 'reasons' => array_column($this->failedChecks(), 'detail') ?: ['No setup today']];
        }

        $config = config('swing.qualification');

        if (! $config['require_backtest_evidence']) {
            return ['status' => 'QUALIFIED', 'reasons' => []];
        }

        if (! $evidence || ($evidence['trades'] ?? 0) < $config['min_backtest_trades']) {
            return [
                'status' => 'NOT_QUALIFIED',
                'reasons' => [sprintf(
                    'No validated history for this strategy (%d of %d trades recorded). A setup can look right and still have no measured edge.',
                    $evidence['trades'] ?? 0, $config['min_backtest_trades']
                )],
            ];
        }

        if (($evidence['expectancy_r'] ?? -INF) < $config['min_expectancy_r']) {
            return [
                'status' => 'NOT_QUALIFIED',
                'reasons' => [sprintf('Backtested expectancy is %+.3fR, below the %+.2fR required', $evidence['expectancy_r'], $config['min_expectancy_r'])],
            ];
        }

        return ['status' => 'QUALIFIED', 'reasons' => []];
    }

    /** The conditions that failed, for a card that explains itself. */
    public function failedChecks(): array
    {
        return array_values(array_filter($this->checks, fn ($c) => ! $c['pass']));
    }
}
