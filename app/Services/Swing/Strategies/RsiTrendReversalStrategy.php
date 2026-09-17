<?php

namespace App\Services\Swing\Strategies;

use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingSignal;
use App\Services\Swing\SwingStrategy;

/**
 * An uptrend that weakened, held, and has started turning back up.
 *
 * Explicitly NOT "RSI below 30 is a buy". In a downtrend RSI sits under 30 for weeks while
 * price keeps falling, and buying it is catching a knife. What is looked for here is the
 * sequence the brief describes: an established trend, a controlled loss of momentum,
 * support holding, RSI recovering, and price confirming before anything is taken.
 *
 * The RSI band is deliberately mid-range rather than oversold. A stock in a genuine
 * uptrend rarely reaches 30 on an ordinary pullback, so waiting for 30 means only ever
 * buying the ones whose trend has already broken.
 */
class RsiTrendReversalStrategy extends SwingStrategy
{
    public function name(): string
    {
        return 'RSI Trend Reversal';
    }

    public function evaluate(SwingContext $context): SwingSignal
    {
        $config = config('swing.strategies.rsi_reversal');
        $closes = $context->closes();
        $daily = $context->daily;
        $bar = $context->bar();
        $previous = $daily[count($daily) - 2] ?? null;

        $trendEma = $this->indicators->ema($closes, $config['trend_ema']);
        $longMa = $this->indicators->sma($closes, $config['long_ma']);
        $rsi = $this->indicators->rsi($closes);

        if (! $trendEma || ! $longMa || $rsi === null || ! $previous || ! $context->atr) {
            return $this->noSetup($context, [$this->check('History', false, 'Not enough history for the reversal test')]);
        }

        // Did momentum actually weaken recently? Without this every strong stock qualifies,
        // and the strategy becomes a second trend-follower wearing an RSI badge.
        $weakest = 100.0;

        for ($back = 1; $back <= $config['lookback']; $back++) {
            $past = $this->indicators->rsi(array_slice($closes, 0, count($closes) - $back));

            if ($past !== null) {
                $weakest = min($weakest, $past);
            }
        }

        $range = $bar['high'] - $bar['low'];
        $confirmation = $range > 0 && ($bar['close'] - $bar['low']) / $range >= 0.5 && $bar['close'] > $previous['close'];

        $checks = [
            $this->check('Trend still intact', $context->close() > $longMa,
                sprintf('Price %.2f vs %d SMA %.2f', $context->close(), $config['long_ma'], $longMa)),

            $this->check('Momentum weakened', $weakest <= $config['pullback_rsi'],
                sprintf('RSI fell to %.1f in the last %d bars, needed %d or lower', $weakest, $config['lookback'], $config['pullback_rsi'])),

            $this->check('Momentum recovering', $rsi >= $config['recovery_rsi'],
                sprintf('RSI back to %.1f, needs %d', $rsi, $config['recovery_rsi'])),

            $this->check('Support held', $context->close() > $trendEma * 0.98,
                sprintf('Price %.2f against the %d EMA %.2f', $context->close(), $config['trend_ema'], $trendEma)),

            $this->check('Price confirms the turn', $confirmation,
                $confirmation ? 'Closed strongly off the low and above yesterday' : 'No confirmation bar yet'),
        ];

        if (! $this->allPassed($checks)) {
            // Weakened and holding, but not turning yet — the case worth watching
            if ($weakest <= $config['pullback_rsi'] && $rsi < $config['recovery_rsi'] && $context->close() > $longMa) {
                return $this->watchlist($context, $checks, sprintf(
                    'Pullback in an intact trend. Waiting for RSI back above %d with a confirming close.',
                    $config['recovery_rsi']
                ));
            }

            return $this->noSetup($context, $checks);
        }

        $trigger = $bar['high'];
        $stop = $this->stopFor($context, 'BUY', $trigger);

        return $stop === null
            ? $this->noSetup($context, $checks)
            : $this->ready($context, 'BUY', $trigger, $stop, config('swing.risk.stop_method'), $checks, entryTrigger: $trigger);
    }
}
