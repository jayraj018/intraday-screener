<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * A level that broke, was come back to, and held.
 *
 * Different from a plain breakout in what it is waiting for. The breakout already
 * happened; this one only becomes interesting once price has returned to the broken level
 * and refused to go back under it. Old resistance behaving as support is the evidence.
 *
 * The invalidation matters as much as the entry: a close materially back below the level
 * means the break failed, and the setup is dead rather than merely losing.
 *
 * Retest volume is compared with breakout volume on purpose. A retest that arrives on
 * heavier volume than the break is distribution, not a pullback.
 */
class BreakoutRetestStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'Breakout Retest';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.breakout_retest');
        $daily = $context->daily;
        $bar = $context->bar();
        $previous = $daily[count($daily) - 2] ?? null;

        $level = $this->resistance($context, $config);

        if (! $level || ! $previous || ! $context->atr) {
            return $this->noSetup($context, [
                $this->check('Resistance', false, 'No level tested often enough to break'),
            ]);
        }

        $breakout = $this->breakout($daily, $level['price'], $config);

        if (! $breakout) {
            return $this->noSetup($context, [
                $this->check('Breakout', false, sprintf('No close above %.2f in the last %d bars', $level['price'], $config['breakout_within_bars'])),
            ]);
        }

        $distance = abs($context->close() - $level['price']) / $context->atr;
        $lossBelow = ($level['price'] - $context->close()) / $level['price'] * 100;
        $range = $bar['high'] - $bar['low'];
        $confirmation = $range > 0 && ($bar['close'] - $bar['low']) / $range >= 0.5 && $bar['close'] > $previous['close'];

        $checks = [
            $this->check('Level broken', true,
                sprintf('%.2f broken %d bars ago on %.2fx volume', $level['price'], $breakout['bars_ago'], $breakout['relative_volume'])),

            $this->check('Price came back to it', $distance <= $config['retest_distance_atr'],
                sprintf('%.2f ATR from the level, wanted within %.2f', $distance, $config['retest_distance_atr'])),

            $this->check('Level held', $lossBelow <= $config['max_loss_below_percent'],
                $lossBelow > 0
                    ? sprintf('Closed %.1f%% below the level, limit %.1f%%', $lossBelow, $config['max_loss_below_percent'])
                    : 'Held above the broken level'),

            // Heavier volume on the retest than on the break is selling, not a pullback
            $this->check('Retest is quieter than the break',
                $context->relativeVolume() !== null && $context->relativeVolume() < $breakout['relative_volume'],
                sprintf('Retest %.2fx vs breakout %.2fx', $context->relativeVolume() ?? 0, $breakout['relative_volume'])),

            $this->check('Price confirms the hold', $confirmation,
                $confirmation ? 'Closed strongly off the low and above yesterday' : 'No confirmation bar yet'),
        ];

        if (! $this->allPassed($checks)) {
            if ($lossBelow > $config['max_loss_below_percent']) {
                return $this->noSetup($context, $checks); // the break failed; not a watchlist
            }

            if ($distance <= $config['retest_distance_atr']) {
                return $this->watchlist($context, $checks, sprintf(
                    'Retesting %.2f after breaking it. Waiting for a close that holds above the level.',
                    $level['price']
                ));
            }

            return $this->noSetup($context, $checks);
        }

        $trigger = $bar['high'];

        // Below the level it just defended: if that goes, the retest failed
        $stop = $level['price'] * (1 - config('swing.risk.swing_buffer'));

        return $this->ready($context, 'BUY', $trigger, $stop, 'breakout_level', $checks, entryTrigger: $trigger);
    }

    /** The most recent close above the level, and how heavy it was. */
    protected function breakout(array $daily, float $level, array $config): ?array
    {
        $lastIndex = count($daily) - 1;
        $volumes = array_column($daily, 'volume');

        for ($back = 1; $back <= $config['breakout_within_bars']; $back++) {
            $i = $lastIndex - $back;

            if ($i < 20 || $daily[$i]['close'] <= $level) {
                continue;
            }

            $average = array_sum(array_slice($volumes, $i - 20, 20)) / 20;

            return [
                'bars_ago' => $back,
                'index' => $i,
                'relative_volume' => $average > 0 ? round($daily[$i]['volume'] / $average, 2) : 0.0,
            ];
        }

        return null;
    }

    /**
     * The nearest level below price tested more than once, clustered within an ATR so
     * repeated tests of one level are not counted as several different levels.
     */
    protected function resistance(SwingContext $context, array $config): ?array
    {
        $pivots = $this->indicators->swingHighs($context->daily, 2);
        $lastIndex = count($context->daily) - 1;
        $atr = $context->atr;

        if (! $pivots || ! $atr) {
            return null;
        }

        $levels = [];

        foreach ($pivots as $pivot) {
            if ($pivot['confirmed_at'] > $lastIndex || $pivot['index'] < $lastIndex - $config['resistance_lookback']) {
                continue;
            }

            foreach ($levels as $i => $existing) {
                if (abs($pivot['price'] - $existing['price']) <= $atr) {
                    $levels[$i]['touches']++;
                    $levels[$i]['price'] = max($existing['price'], $pivot['price']);

                    continue 2;
                }
            }

            $levels[] = ['price' => $pivot['price'], 'touches' => 1];
        }

        $tested = array_values(array_filter($levels, fn ($l) => $l['touches'] >= $config['min_touches']));

        if (! $tested) {
            return null;
        }

        usort($tested, fn ($a, $b) => $b['price'] <=> $a['price']);

        return $tested[0];
    }
}
