<?php

namespace App\Services\Swing;

use App\Models\BacktestTrade;

/**
 * How long a strategy's trades have actually lasted.
 *
 * Read from recorded trades, never asserted. A card that promises "3-10 days" without
 * anything behind it is worse than one that says the answer is not known, because the
 * first invites a position to be sized and planned around a number nobody measured.
 *
 * Below the configured trade count the answer is NOT ENOUGH DATA.
 */
class HoldingPeriodService
{
    /**
     * @return array{known: bool, label: string, lower: ?int, median: ?int, upper: ?int, trades: int}
     */
    public function estimate(string $strategy, ?int $runId = null): array
    {
        $config = config('swing.holding_period');

        $bars = BacktestTrade::query()
            ->where('system', 'swing')
            ->where('strategy', $strategy)
            ->when($runId, fn ($q) => $q->where('run_id', $runId))
            ->orderBy('bars_held')
            ->pluck('bars_held')
            ->all();

        if (count($bars) < $config['min_trades']) {
            return [
                'known' => false,
                'label' => 'NOT ENOUGH DATA',
                'lower' => null,
                'median' => null,
                'upper' => null,
                'trades' => count($bars),
            ];
        }

        $at = fn (float $percent) => (int) $bars[max(0, min(
            (int) floor($percent / 100 * (count($bars) - 1)),
            count($bars) - 1
        ))];

        $lower = $at($config['lower_percentile']);
        $upper = $at($config['upper_percentile']);

        return [
            'known' => true,
            // The middle half of outcomes, not the extremes: one trade held for eighty
            // days should not widen the estimate everyone reads.
            'label' => $lower === $upper ? "{$lower} trading days" : "{$lower}-{$upper} trading days",
            'lower' => $lower,
            'median' => $at(50),
            'upper' => $upper,
            'trades' => count($bars),
        ];
    }
}
