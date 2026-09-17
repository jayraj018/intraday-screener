<?php

namespace App\Services\Swing;

use App\Services\IndicatorService;

/**
 * What every swing strategy shares: how the checks are recorded, where the stop goes, and
 * how far past its trigger a setup is still worth taking.
 *
 * Kept in one place so seven strategies cannot end up with seven slightly different stop
 * rules — which is exactly how the intraday screener came to disagree with its own
 * analysis page.
 */
abstract class SwingStrategy implements SwingStrategyInterface
{
    public function __construct(protected IndicatorService $indicators)
    {
    }

    protected function check(string $label, bool $pass, string $detail): array
    {
        return ['label' => $label, 'pass' => $pass, 'detail' => $detail];
    }

    protected function allPassed(array $checks): bool
    {
        return ! in_array(false, array_column($checks, 'pass'), true);
    }

    protected function noSetup(SwingContext $context, array $checks): SwingSignal
    {
        return new SwingSignal($this->name(), $context->symbol, SwingSignal::NO_SETUP, $checks);
    }

    protected function watchlist(SwingContext $context, array $checks, string $watchFor): SwingSignal
    {
        return new SwingSignal($this->name(), $context->symbol, SwingSignal::WATCHLIST, $checks, watchFor: $watchFor);
    }

    /**
     * Turn a passing set of checks into a plan — or into EXTENDED when price has already
     * run too far past the trigger for that plan to be the one being taken.
     */
    protected function ready(
        SwingContext $context,
        string $direction,
        float $trigger,
        float $stop,
        string $stopMethod,
        array $checks,
        ?float $entryTrigger = null,
    ): SwingSignal {
        $risk = config('swing.risk');
        $atr = $context->atr;
        $isBuy = $direction === 'BUY';

        // The move already happened. Entering here risks a different amount to the plan,
        // so it is reported as EXTENDED rather than quietly re-priced.
        if ($atr) {
            $extension = $isBuy ? $context->close() - $trigger : $trigger - $context->close();

            if ($extension > $atr * $risk['max_extension_atr']) {
                return new SwingSignal(
                    $this->name(), $context->symbol, SwingSignal::EXTENDED, $checks,
                    watchFor: sprintf('Price is already %.2f past the %.2f trigger. Wait for a pullback.', $extension, $trigger),
                );
            }
        }

        $distance = abs($trigger - $stop);

        if ($distance <= 0) {
            return $this->noSetup($context, $checks);
        }

        $setup = new SwingSetup(
            symbol: $context->symbol,
            strategy: $this->name(),
            direction: $direction,
            signalDate: $context->date(),
            signalPrice: $context->close(),
            stop: round($stop, 2),
            stopMethod: $stopMethod,
            target1: round($isBuy ? $trigger + $distance * $risk['target1_r'] : $trigger - $distance * $risk['target1_r'], 2),
            target2: round($isBuy ? $trigger + $distance * $risk['target2_r'] : $trigger - $distance * $risk['target2_r'], 2),
            reasons: array_column(array_filter($checks, fn ($c) => $c['pass']), 'detail'),
            entryTrigger: $entryTrigger !== null ? round($entryTrigger, 2) : null,
        );

        return new SwingSignal($this->name(), $context->symbol, SwingSignal::READY, $checks, setup: $setup);
    }

    /**
     * Where the stop goes, by the configured method.
     *
     * `structure_atr` is the default because a pure structural stop can land inside the
     * day-to-day noise when a pullback is shallow — the trade is then stopped out by
     * nothing happening. Taking the wider of the pivot and a minimum ATR distance keeps
     * the level meaningful without abandoning the structure.
     */
    protected function stopFor(SwingContext $context, string $direction, float $reference): ?float
    {
        $risk = config('swing.risk');
        $isBuy = $direction === 'BUY';
        $atr = $context->atr;

        $atrStop = $atr ? ($isBuy ? $reference - $atr * $risk['atr_multiplier'] : $reference + $atr * $risk['atr_multiplier']) : null;
        $pivot = $this->lastPivot($context, $isBuy, $risk);

        $structural = $pivot === null ? null : ($isBuy
            ? $pivot * (1 - $risk['swing_buffer'])
            : $pivot * (1 + $risk['swing_buffer']));

        return match ($risk['stop_method']) {
            'atr' => $atrStop,
            'swing_low' => $structural,

            // The average the whole idea rests on. If price closes through it the setup
            // is not merely losing, it is no longer the setup that was taken.
            'ema' => $this->emaStop($context, $isBuy, $risk),
            default => match (true) {
                $structural === null => $atrStop,
                $atrStop === null => $structural,
                // The wider of the two, so the stop is never inside the noise
                default => $isBuy ? min($structural, $atrStop) : max($structural, $atrStop),
            },
        };
    }

    /**
     * A stop just beyond the moving average the trend is being read from.
     */
    protected function emaStop(SwingContext $context, bool $isBuy, array $risk): ?float
    {
        $ema = $this->indicators->ema($context->closes(), config('swing.strategies.trend_pullback.fast'));

        if (! $ema) {
            return null;
        }

        return $isBuy ? $ema * (1 - $risk['swing_buffer']) : $ema * (1 + $risk['swing_buffer']);
    }

    /**
     * The most recent CONFIRMED pivot. Confirmation matters: a pivot is only knowable
     * once the bars proving it have printed, and reading an unconfirmed one would be
     * using the future to place today's stop.
     */
    protected function lastPivot(SwingContext $context, bool $isBuy, array $risk): ?float
    {
        $lookback = $risk['swing_lookback'];
        $pivots = $isBuy
            ? $this->indicators->swingLows($context->daily, 2)
            : $this->indicators->swingHighs($context->daily, 2);

        $lastIndex = count($context->daily) - 1;

        $recent = array_filter(
            $pivots,
            fn ($p) => $p['confirmed_at'] <= $lastIndex && $p['index'] >= $lastIndex - $lookback * 4
        );

        return $recent ? end($recent)['price'] : null;
    }

    /** Is a moving average pointing up over the configured span? */
    protected function isRising(array $values, int $lookback): bool
    {
        if (count($values) < $lookback + 1) {
            return false;
        }

        $keys = array_keys($values);
        $now = $values[end($keys)];
        $then = $values[$keys[count($keys) - 1 - $lookback]];

        return $now > $then;
    }
}
