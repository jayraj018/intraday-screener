<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * A range that has gone quiet, then broken out of itself on expanding volume.
 *
 * The contraction is the setup, never the trade. Buying a stock because it has gone quiet
 * is buying a coin flip — quiet ranges resolve in both directions, and the strategy has no
 * view on which until price picks one. So the contraction only earns a place on the
 * watchlist; the trade needs the expansion.
 *
 * Band width is compared to its OWN recent distribution rather than a fixed number: a
 * width that is tight for one stock is ordinary for another.
 */
class VolatilityContractionStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'Volatility Contraction';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.volatility_contraction');
        $closes = $context->closes();
        $daily = $context->daily;
        $bar = $context->bar();

        $widths = $this->widthSeries($closes, $config['width_lookback']);
        $relativeVolume = $context->relativeVolume();

        if (count($widths) < 30 || ! $context->atr) {
            return $this->noSetup($context, [$this->check('History', false, 'Not enough history to judge contraction')]);
        }

        $threshold = $this->indicators->percentile($widths, $config['width_percentile']);

        // The width as it stood BEFORE this bar. Once a breakout happens the bands widen,
        // so measuring the contraction on the breakout bar would hide the thing being
        // looked for.
        $priorWidths = array_slice($widths, -$config['contraction_bars'] - 1, $config['contraction_bars']);
        $wasContracted = $priorWidths && max($priorWidths) <= $threshold;

        $rangeBefore = $this->averageRange(array_slice($daily, -$config['contraction_bars'] - 1, $config['contraction_bars']));
        $todayRange = $bar['high'] - $bar['low'];

        // The range the price broke out of
        $recent = array_slice($daily, -$config['contraction_bars'] - 1, $config['contraction_bars']);
        $rangeHigh = max(array_column($recent, 'high'));

        $checks = [
            $this->check('Volatility contracted', $wasContracted,
                sprintf('Band width was %.4f against a %dth-percentile threshold of %.4f',
                    $priorWidths ? max($priorWidths) : 0, $config['width_percentile'], $threshold)),

            $this->check('Range expanded', $rangeBefore > 0 && $todayRange > $rangeBefore,
                sprintf('Today %.2f against a recent average of %.2f', $todayRange, $rangeBefore)),

            $this->check('Broke out of the range', $bar['close'] > $rangeHigh,
                sprintf('Close %.2f vs range high %.2f', $bar['close'], $rangeHigh)),

            $this->check('Volume expanded', $relativeVolume !== null && $relativeVolume >= $config['min_breakout_relative_volume'],
                sprintf('%.2fx average, needs %.1fx', $relativeVolume ?? 0, $config['min_breakout_relative_volume'])),
        ];

        if (! $this->allPassed($checks)) {
            // Coiled but not yet resolved — the textbook watchlist case for this setup
            if ($wasContracted && $bar['close'] <= $rangeHigh) {
                return $this->watchlist($context, $checks, sprintf(
                    'Coiled in a %.2f-%.2f range. Waiting for a close above %.2f on %.1fx volume.',
                    min(array_column($recent, 'low')), $rangeHigh, $rangeHigh, $config['min_breakout_relative_volume']
                ));
            }

            return $this->noSetup($context, $checks);
        }

        // The range low is where the idea fails: back inside it and the breakout was noise
        $stop = min(array_column($recent, 'low')) * (1 - config('swing.risk.swing_buffer'));

        return $this->ready($context, 'BUY', $bar['close'], $stop, 'range_low', $checks);
    }

    /** Bollinger band width at each of the last $lookback bars. */
    protected function widthSeries(array $closes, int $lookback): array
    {
        $widths = [];
        $total = count($closes);

        for ($back = min($lookback, $total - 20); $back >= 0; $back--) {
            $width = $this->indicators->bollingerWidth(array_slice($closes, 0, $total - $back));

            if ($width !== null) {
                $widths[] = $width;
            }
        }

        return $widths;
    }

    protected function averageRange(array $candles): float
    {
        if (! $candles) {
            return 0.0;
        }

        return array_sum(array_map(fn ($c) => $c['high'] - $c['low'], $candles)) / count($candles);
    }
}
