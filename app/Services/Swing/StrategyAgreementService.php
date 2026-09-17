<?php

namespace App\Services\Swing;

/**
 * How much independent evidence actually supports a stock.
 *
 * "4 of 7 strategies agree" is only meaningful if those four are looking at different
 * things. MA Trend Following and Trend Pullback both rest on the 20/50 relationship;
 * counting them as two votes is the same double-count as adding three moving-average
 * features together.
 *
 * So agreement is counted in FAMILIES. Four strategies from one family is one piece of
 * evidence, and the raw count is reported alongside it so the difference is visible
 * rather than hidden.
 */
class StrategyAgreementService
{
    /**
     * @param  array<int, SwingSignal>  $signals  every strategy's verdict on one stock
     * @return array{families: int, strategies: int, agreeing: array<string, array<int, string>>, qualifies: bool, direction: ?string}
     */
    public function assess(array $signals): array
    {
        $config = config('swing.agreement');

        $actionable = array_filter($signals, fn (SwingSignal $s) => $s->isActionable());

        if (! $actionable) {
            return ['families' => 0, 'strategies' => 0, 'agreeing' => [], 'qualifies' => false, 'direction' => null];
        }

        // A stock with setups pointing both ways is not agreement, it is disagreement
        $directions = array_unique(array_map(fn (SwingSignal $s) => $s->setup->direction, $actionable));

        if (count($directions) > 1) {
            return [
                'families' => 0,
                'strategies' => count($actionable),
                'agreeing' => [],
                'qualifies' => false,
                'direction' => null,
            ];
        }

        $agreeing = [];

        foreach ($actionable as $signal) {
            // A strategy with no declared family is treated as its own, rather than
            // silently folded in with something it may share nothing with
            $family = $config['families'][$signal->strategy] ?? $signal->strategy;
            $agreeing[$family][] = $signal->strategy;
        }

        return [
            'families' => count($agreeing),
            'strategies' => count($actionable),
            'agreeing' => $agreeing,
            'qualifies' => count($agreeing) >= $config['min_families_agree'],
            'direction' => reset($directions),
        ];
    }

    /**
     * A plain-language reading, which matters because "3 of 7" and "3 of 7, all trend
     * followers" mean very different things to whoever is about to take the trade.
     */
    public function describe(array $assessment): string
    {
        if ($assessment['strategies'] === 0) {
            return 'No strategy has an actionable setup.';
        }

        if ($assessment['direction'] === null) {
            return 'Strategies disagree on direction.';
        }

        $families = implode(', ', array_keys($assessment['agreeing']));

        return $assessment['strategies'] === $assessment['families']
            ? sprintf('%d independent %s: %s', $assessment['families'],
                $assessment['families'] === 1 ? 'signal' : 'signals', $families)
            : sprintf('%d strategies, but only %d independent %s (%s) — the rest overlap',
                $assessment['strategies'], $assessment['families'],
                $assessment['families'] === 1 ? 'family' : 'families', $families);
    }
}
