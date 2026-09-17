<?php

namespace App\Services\Swing;

use App\Services\IndicatorService;

/**
 * Scores a setup out of 100, from grouped features.
 *
 * The grouping is the point. Price above the 20 EMA, the 20 above the 50, and the 50
 * rising are three observations of ONE trend. Summing them lets a single piece of
 * information score three times, and a setup then looks independently confirmed by things
 * that are really the same thing. Each group therefore contributes the MEAN of its
 * members, so adding a fourth correlated feature sharpens the reading without inflating
 * it.
 *
 * The weights are the brief's own example values. They have not been validated, and this
 * class makes no claim that they are optimal — that is what walk-forward is for.
 */
class SignalScoringService
{
    public function __construct(protected IndicatorService $indicators)
    {
    }

    /**
     * @return array{score: int, groups: array<string, array{value: float, weight: int, points: float, detail: string}>}
     */
    public function score(SwingContext $context, ?SwingSetup $setup = null): array
    {
        $config = config('swing.scoring');
        $isBuy = $setup === null || $setup->isBuy();

        $groups = [
            'trend' => $this->trend($context, $isBuy),
            'momentum' => $this->momentum($context, $config),
            'volume' => $this->volume($context, $config),
            'relative_strength' => $this->relativeStrength($context),
            'price_structure' => $this->priceStructure($context, $isBuy),
            'market_regime' => $this->marketRegime($context, $isBuy),
            'risk_reward' => $this->riskReward($setup, $config),
        ];

        $total = 0.0;

        foreach ($groups as $name => $group) {
            $weight = $config['weights'][$name] ?? 0;
            $points = $group['value'] * $weight;
            $groups[$name]['weight'] = $weight;
            $groups[$name]['points'] = round($points, 1);
            $total += $points;
        }

        return ['score' => (int) round($total), 'groups' => $groups];
    }

    /** One reading of the trend, from four correlated observations of it. */
    protected function trend(SwingContext $context, bool $isBuy): array
    {
        $closes = $context->closes();
        $close = $context->close();

        $fast = $this->indicators->ema($closes, 20);
        $slow = $this->indicators->ema($closes, 50);
        $long = $this->indicators->sma($closes, 200);
        $slowSeries = $this->indicators->emaArray($closes, 50);

        if (! $fast || ! $slow) {
            return $this->unknown('Not enough history for the moving averages');
        }

        $rising = count($slowSeries) > 11
            && $slowSeries[array_key_last($slowSeries)] > array_values($slowSeries)[count($slowSeries) - 11];

        $observations = [
            $isBuy ? $close > $fast : $close < $fast,
            $isBuy ? $fast > $slow : $fast < $slow,
            $isBuy ? $rising : ! $rising,
            $long === null ? null : ($isBuy ? $close > $long : $close < $long),
        ];

        return $this->mean($observations, sprintf('%d of %d trend conditions met',
            count(array_filter($observations)), count(array_filter($observations, fn ($o) => $o !== null))));
    }

    protected function momentum(SwingContext $context, array $config): array
    {
        $closes = $context->closes();
        $rsi = $this->indicators->rsi($closes);
        $roc = $this->indicators->rateOfChange($closes, 20);

        if ($rsi === null || $roc === null) {
            return $this->unknown('Not enough history for momentum');
        }

        [$floor, $ceiling] = $config['rsi_band'];

        return $this->mean(
            [$rsi >= $floor && $rsi <= $ceiling, $roc > 0],
            sprintf('RSI %.1f (band %d-%d), 20-day return %+.1f%%', $rsi, $floor, $ceiling, $roc),
        );
    }

    /** Relative volume, scaled rather than thresholded: 1.5x should beat 1.1x. */
    protected function volume(SwingContext $context, array $config): array
    {
        $relative = $context->relativeVolume();

        if ($relative === null) {
            return $this->unknown('No volume history');
        }

        $value = ($relative - 1.0) / max(0.01, $config['volume_saturation'] - 1.0);

        return [
            'value' => $this->clamp($value),
            'detail' => sprintf('%.2fx average volume', $relative),
        ];
    }

    protected function relativeStrength(SwingContext $context): array
    {
        $periods = array_filter($context->relativeStrength['periods'], fn ($v) => $v !== null);

        if (! $periods) {
            return $this->unknown('Not enough history against the benchmark');
        }

        return $this->mean(
            array_map(fn ($v) => $v > 0, $periods),
            sprintf('Ahead of NIFTY over %d of %d spans', count(array_filter($periods, fn ($v) => $v > 0)), count($periods)),
        );
    }

    /**
     * Whether the recent pivots are stepping up: higher highs and higher lows. This is
     * the one group that reads structure rather than an indicator, which is why it is
     * kept apart from trend.
     */
    protected function priceStructure(SwingContext $context, bool $isBuy): array
    {
        $highs = $this->indicators->swingHighs($context->daily, 2);
        $lows = $this->indicators->swingLows($context->daily, 2);
        $lastIndex = count($context->daily) - 1;

        $confirmed = fn ($pivots) => array_values(array_filter($pivots, fn ($p) => $p['confirmed_at'] <= $lastIndex));

        $highs = $confirmed($highs);
        $lows = $confirmed($lows);

        if (count($highs) < 2 || count($lows) < 2) {
            return $this->unknown('Too few confirmed pivots to read structure');
        }

        $higherHigh = end($highs)['price'] > $highs[count($highs) - 2]['price'];
        $higherLow = end($lows)['price'] > $lows[count($lows) - 2]['price'];

        $observations = $isBuy ? [$higherHigh, $higherLow] : [! $higherHigh, ! $higherLow];

        return $this->mean($observations, match (true) {
            $higherHigh && $higherLow => 'Higher high and higher low',
            $higherHigh || $higherLow => 'Structure is mixed',
            default => 'Lower high and lower low',
        });
    }

    protected function marketRegime(SwingContext $context, bool $isBuy): array
    {
        $trend = $context->regime['trend'] ?? MarketRegimeService::UNKNOWN;
        $volatility = $context->regime['volatility'] ?? MarketRegimeService::UNKNOWN;

        $withTrend = match ($trend) {
            'BULLISH_TREND' => $isBuy ? 1.0 : 0.0,
            'BEARISH_TREND' => $isBuy ? 0.0 : 1.0,
            'SIDEWAYS' => 0.3,   // not fatal, but not support either
            default => 0.5,
        };

        // Extreme volatility in either direction makes a planned stop less reliable
        $calm = match ($volatility) {
            'NORMAL' => 1.0,
            'LOW_VOLATILITY' => 0.7,
            'HIGH_VOLATILITY' => 0.4,
            default => 0.5,
        };

        return [
            'value' => $this->clamp(($withTrend + $calm) / 2),
            'detail' => "NIFTY {$trend}, volatility {$volatility}",
        ];
    }

    protected function riskReward(?SwingSetup $setup, array $config): array
    {
        if (! $setup) {
            return $this->unknown('No plan to price the risk of');
        }

        $rr = $setup->rMultiple($setup->target1);

        if ($rr === null) {
            return $this->unknown('Risk could not be measured');
        }

        return [
            'value' => $this->clamp($rr / $config['rr_saturation']),
            'detail' => sprintf('Target 1 at %.1fR', $rr),
        ];
    }

    /**
     * The mean of a group's observations, skipping any that could not be measured. Using
     * the mean rather than the sum is what stops correlated features compounding.
     */
    protected function mean(array $observations, string $detail): array
    {
        $measured = array_filter($observations, fn ($o) => $o !== null);

        if (! $measured) {
            return $this->unknown($detail);
        }

        return [
            'value' => count(array_filter($measured)) / count($measured),
            'detail' => $detail,
        ];
    }

    /** Scores neither for nor against: an unmeasurable group must not look like a failure. */
    protected function unknown(string $detail): array
    {
        return ['value' => 0.5, 'detail' => $detail . ' (not measured)'];
    }

    protected function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
