<?php

namespace App\Services\Swing;

/**
 * One train / validate / test cycle, and what happened in it.
 *
 * A rejected fold carries its reason and no test metrics at all — not zeros, not nulls
 * that might be averaged in by accident. If the chosen configuration did not survive
 * validation, it was never run out of sample, and there is nothing to report.
 */
final class WalkForwardFold
{
    public function __construct(
        public readonly int $number,
        public readonly WalkForwardWindow $train,
        public readonly WalkForwardWindow $validate,
        public readonly WalkForwardWindow $test,
        public readonly int $candidatesTried,
        public readonly ?array $chosen = null,
        public readonly ?array $trainMetrics = null,
        public readonly ?array $validateMetrics = null,
        public readonly ?array $testMetrics = null,
        public readonly ?string $rejectedBecause = null,
    ) {
    }

    public function accepted(): bool
    {
        return $this->rejectedBecause === null && $this->testMetrics !== null;
    }
}
