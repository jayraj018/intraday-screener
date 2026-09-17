<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * A close above a resistance level that has actually been tested, backed by volume.
 *
 * Two things separate this from "price made a new high". Resistance must have been
 * touched more than once — a level tested once is not a level, it is a high. And the
 * break must be a daily CLOSE above it: a wick through resistance that closes back
 * underneath is a failed breakout, and counting it as a success is how a breakout
 * strategy comes to look far better than it trades.
 */
class BreakoutVolumeStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'Breakout + Volume';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.breakout_volume');
        $close = $context->close();
        $bar = $context->bar();

        $resistance = $this->resistance($context, $config);
        $relativeVolume = $context->relativeVolume();

        if ($resistance === null || ! $context->atr) {
            return $this->noSetup($context, [
                $this->check('Resistance', false, sprintf('No level tested %d+ times in the last %d bars', $config['min_touches'], $config['resistance_lookback'])),
            ]);
        }

        $extension = ($close - $resistance['price']) / $resistance['price'] * 100;

        $checks = [
            $this->check('Resistance established', true,
                sprintf('%.2f, tested %d times', $resistance['price'], $resistance['touches'])),

            // A CLOSE above it, not a wick through it
            $this->check('Closed above resistance', $close > $resistance['price'],
                sprintf('Close %.2f vs resistance %.2f (high was %.2f)', $close, $resistance['price'], $bar['high'])),

            $this->check('Volume confirmed', $relativeVolume !== null && $relativeVolume >= $config['min_relative_volume'],
                sprintf('%.2fx average, needs %.1fx', $relativeVolume ?? 0, $config['min_relative_volume'])),

            $this->check('Not already extended', $extension <= $config['max_extension_percent'],
                sprintf('%.1f%% above the level, limit %.1f%%', $extension, $config['max_extension_percent'])),

            $this->check('Market regime', ! $context->marketIsSideways(),
                'NIFTY is ' . ($context->regime['trend'] ?? 'UNKNOWN')),
        ];

        if (! $this->allPassed($checks)) {
            // Sitting just under a tested level with volume building is the case worth
            // watching, rather than discarding until it has already gone.
            if ($close <= $resistance['price'] && $close >= $resistance['price'] - $context->atr * config('swing.risk.watchlist_distance_atr')) {
                return $this->watchlist($context, $checks, sprintf(
                    'Needs a daily close above %.2f on %.1fx volume.',
                    $resistance['price'], $config['min_relative_volume']
                ));
            }

            return $this->noSetup($context, $checks);
        }

        // The broken level is where the idea fails, so the stop belongs just under it
        $stop = $this->stopFor($context, 'BUY', $close);
        $belowLevel = $resistance['price'] * (1 - config('swing.risk.swing_buffer'));
        $stop = $stop === null ? $belowLevel : min($stop, $belowLevel);

        return $this->ready($context, 'BUY', $close, $stop, 'breakout_level', $checks);
    }

    /**
     * The nearest level below price that has been tested more than once.
     *
     * Built from confirmed swing highs, clustered: several pivots within an ATR of each
     * other are one level being tested repeatedly, not several different levels.
     */
    protected function resistance(SwingContext $context, array $config): ?array
    {
        $pivots = $this->indicators->swingHighs($context->daily, 2);
        $lastIndex = count($context->daily) - 1;
        $atr = $context->atr;

        if (! $pivots || ! $atr) {
            return null;
        }

        $recent = array_filter($pivots, fn ($p) => $p['confirmed_at'] <= $lastIndex && $p['index'] >= $lastIndex - $config['resistance_lookback']);

        $levels = [];

        foreach ($recent as $pivot) {
            foreach ($levels as $i => $level) {
                if (abs($pivot['price'] - $level['price']) <= $atr) {
                    $levels[$i]['touches']++;
                    $levels[$i]['price'] = max($level['price'], $pivot['price']);

                    continue 2;
                }
            }

            $levels[] = ['price' => $pivot['price'], 'touches' => 1];
        }

        $tested = array_filter($levels, fn ($l) => $l['touches'] >= $config['min_touches']);

        if (! $tested) {
            return null;
        }

        // The one price is actually contending with: the highest tested level it has not
        // already left far behind
        usort($tested, fn ($a, $b) => $b['price'] <=> $a['price']);

        return $tested[0];
    }
}
