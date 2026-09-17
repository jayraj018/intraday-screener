<?php

namespace App\Services\Swing;

/**
 * What a walk-forward run is allowed to claim.
 *
 * Only accepted folds contribute. A fold whose chosen configuration failed validation was
 * never measured out of sample, so it has nothing to add — and silently treating that as
 * a zero would drag an honest average towards a number nobody measured.
 */
final class WalkForwardResult
{
    /** @param array<int, WalkForwardFold> $folds */
    public function __construct(
        public readonly array $folds,
        public readonly string $objective,
    ) {
    }

    /** @return array<int, WalkForwardFold> */
    public function accepted(): array
    {
        return array_values(array_filter($this->folds, fn (WalkForwardFold $f) => $f->accepted()));
    }

    /** @return array<int, WalkForwardFold> */
    public function rejected(): array
    {
        return array_values(array_filter($this->folds, fn (WalkForwardFold $f) => ! $f->accepted()));
    }

    /**
     * The out-of-sample answer, or null when there isn't one.
     *
     * Null is the honest result of a run where nothing survived validation. Returning
     * zeros instead would read as "no edge measured" when the truth is "nothing was
     * measured at all".
     */
    public function outOfSample(): ?array
    {
        $accepted = $this->accepted();

        if (! $accepted) {
            return null;
        }

        $trades = array_sum(array_map(fn ($f) => $f->testMetrics['trades'], $accepted));

        if ($trades === 0) {
            return null;
        }

        // Weighted by trade count. A plain mean across folds would let a fold with nine
        // trades pull as hard as one with nine hundred.
        $weighted = fn (string $key) => array_sum(array_map(
            fn ($f) => ($f->testMetrics[$key] ?? 0) * $f->testMetrics['trades'], $accepted
        )) / $trades;

        return [
            'folds_accepted' => count($accepted),
            'folds_rejected' => count($this->rejected()),
            'trades' => $trades,
            'expectancy_r' => round($weighted('expectancy_r'), 4),
            'win_rate' => round($weighted('win_rate'), 2),

            // Rebuilt from the underlying sums, because an average of ratios is not the
            // ratio of the averages.
            'profit_factor' => $this->pooledProfitFactor($accepted),

            // How hard the selection worked. Picking the best of fifty candidates is a
            // very different claim from picking the best of three, and a reader cannot
            // judge the result without knowing which happened.
            'candidates_per_fold' => $accepted[0]->candidatesTried,
        ];
    }

    protected function pooledProfitFactor(array $accepted): ?float
    {
        $profit = array_sum(array_map(fn ($f) => $f->testMetrics['gross_profit_r'] ?? 0, $accepted));
        $loss = array_sum(array_map(fn ($f) => $f->testMetrics['gross_loss_r'] ?? 0, $accepted));

        return $loss > 0 ? round($profit / $loss, 4) : null;
    }

    /**
     * A one-line verdict that cannot overstate what was found.
     */
    public function verdict(): string
    {
        $oos = $this->outOfSample();

        if (! $oos) {
            return 'NOT TESTED — no fold survived validation, so nothing was measured out of sample.';
        }

        $edge = $oos['expectancy_r'] > 0 ? 'positive' : 'negative';

        return sprintf(
            'OUT-OF-SAMPLE — %s expectancy of %+.3fR over %s trades across %d of %d folds, best of %d candidates each.',
            $edge, $oos['expectancy_r'], number_format($oos['trades']),
            $oos['folds_accepted'], count($this->folds), $oos['candidates_per_fold']
        );
    }
}
