<?php

namespace App\Services\Swing;

use Illuminate\Support\Carbon;

/**
 * Chooses parameters on one slice of history and measures them on another that had no
 * part in choosing them.
 *
 * The failure this exists to prevent: run every parameter combination over the whole
 * history, report the best one, and call the number a result. That number is the best of
 * however many tries, fitted to noise that will not repeat. Every figure the screener has
 * produced so far is in-sample in exactly that way.
 *
 * The harness knows nothing about strategies. It is handed candidate parameter sets and a
 * function that scores one set over one window, so the same machinery validates a swing
 * strategy, an exit model, or a scoring weight.
 */
class WalkForwardService
{
    /**
     * Cut the range into overlapping train / validate / test folds.
     *
     * @return array<int, array{train: WalkForwardWindow, validate: WalkForwardWindow, test: WalkForwardWindow}>
     */
    public function split(string $from, string $to): array
    {
        $config = config('swing.walk_forward');
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        $folds = [];
        $cursor = $start->copy();
        $number = 1;

        while (true) {
            // Anchored keeps the training window's start fixed so it grows fold by fold;
            // rolling slides it, so a regime that has passed stops being learned from.
            $trainFrom = $config['mode'] === 'anchored' ? $start->copy() : $cursor->copy();
            $trainTo = $cursor->copy()->addMonths($config['train_months'])->subDay();

            $validateFrom = $trainTo->copy()->addDay();
            $validateTo = $validateFrom->copy()->addMonths($config['validate_months'])->subDay();

            $testFrom = $validateTo->copy()->addDay();
            $testTo = $testFrom->copy()->addMonths($config['test_months'])->subDay();

            // A fold whose test window runs past the data is not a fold
            if ($testTo->gt($end)) {
                break;
            }

            $folds[] = [
                'train' => $this->window('train', $number, $trainFrom, $trainTo),
                'validate' => $this->window('validate', $number, $validateFrom, $validateTo),
                'test' => $this->window('test', $number, $testFrom, $testTo),
            ];

            $cursor->addMonths($config['step_months']);
            $number++;
        }

        return $folds;
    }

    protected function window(string $phase, int $fold, Carbon $from, Carbon $to): WalkForwardWindow
    {
        return new WalkForwardWindow(
            phase: $phase,
            fold: $fold,
            from: $from->toDateString(),
            to: $to->toDateString(),
            warmupFrom: $from->copy()->subDays(config('swing.walk_forward.warmup_days'))->toDateString(),
        );
    }

    /**
     * Run the whole thing.
     *
     * @param  array<int, array>  $candidates  parameter sets to choose between
     * @param  callable  $evaluate  fn(array $params, WalkForwardWindow $window): array
     *                              returning at least trades, expectancy_r, win_rate, and
     *                              ideally gross_profit_r / gross_loss_r for pooling
     */
    public function run(string $from, string $to, array $candidates, callable $evaluate): WalkForwardResult
    {
        $config = config('swing.walk_forward');
        $folds = [];

        foreach ($this->split($from, $to) as $windows) {
            $folds[] = $this->runFold($windows, $candidates, $evaluate, $config);
        }

        return new WalkForwardResult($folds, $config['objective']);
    }

    protected function runFold(array $windows, array $candidates, callable $evaluate, array $config): WalkForwardFold
    {
        $number = $windows['train']->fold;
        $reject = fn (string $why) => new WalkForwardFold(
            $number, $windows['train'], $windows['validate'], $windows['test'],
            count($candidates), rejectedBecause: $why
        );

        // --- train: score every candidate --------------------------------------
        $scored = [];

        foreach ($candidates as $params) {
            $metrics = $evaluate($params, $windows['train']);

            // A candidate that barely traded cannot be compared to one that traded often;
            // its apparent edge is a handful of coin flips.
            if (($metrics['trades'] ?? 0) >= $config['min_trades']) {
                $scored[] = ['params' => $params, 'metrics' => $metrics];
            }
        }

        if (! $scored) {
            return $reject('no candidate produced at least ' . $config['min_trades'] . ' trades in training');
        }

        usort($scored, fn ($a, $b) => $b['metrics'][$config['objective']] <=> $a['metrics'][$config['objective']]);
        $best = $scored[0];

        // --- validate: does the choice survive data it was not fitted to? -------
        $validateMetrics = $evaluate($best['params'], $windows['validate']);

        if (($validateMetrics['trades'] ?? 0) < $config['min_trades']) {
            return $reject('the chosen configuration produced too few trades in validation');
        }

        foreach ($config['validation_gate'] as $gate => $threshold) {
            $key = str_replace('min_', '', $gate);

            if (($validateMetrics[$key] ?? -INF) < $threshold) {
                return $reject(sprintf(
                    'the chosen configuration failed validation on %s (%.3f, needed %.3f)',
                    $key, $validateMetrics[$key] ?? 0, $threshold
                ));
            }
        }

        // --- test: touched only now, and only once ------------------------------
        // Reached after the choice is already made and has already survived validation,
        // so nothing in this window can influence which parameters were picked.
        $testMetrics = $evaluate($best['params'], $windows['test']);

        return new WalkForwardFold(
            $number, $windows['train'], $windows['validate'], $windows['test'],
            candidatesTried: count($candidates),
            chosen: $best['params'],
            trainMetrics: $best['metrics'],
            validateMetrics: $validateMetrics,
            testMetrics: $testMetrics,
        );
    }
}
