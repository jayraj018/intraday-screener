<?php

namespace App\Services\Swing;

use App\Services\IndicatorService;
use App\Services\TradeCostService;

/**
 * Replays one swing setup forward, day by day, until something closes it.
 *
 * The intraday backtester cannot be reused for this: it opens and closes every trade
 * inside a single session, so it has no concept of an overnight gap, a trailing stop, or
 * a position that is still open tomorrow. Those are the whole of swing.
 *
 * Nothing here reads a candle before it prints. The caller passes the index of the bar
 * the signal fired on, and the walk starts at the bar after it — so a strategy cannot
 * accidentally hand this a series that already contains its own outcome.
 */
class SwingBacktestService
{
    protected TradeCostService $costs;

    public function __construct(
        TradeCostService $costs,
        protected IndicatorService $indicators,
    ) {
        // Swing settles as delivery: STT is 0.1% on both legs rather than 0.025% on the
        // sell alone. Charging intraday rates would understate every trade.
        $this->costs = $costs->withRates('swing');
    }

    /**
     * @param  array  $candles  the full daily series
     * @param  int  $signalIndex  the bar the setup fired on; the walk begins after it
     * @param  ?callable  $isInvalidated  fn(array $bar, int $index): bool — the strategy's
     *                                    own "this idea is dead" rule, checked each close
     */
    public function walk(SwingSetup $setup, array $candles, int $signalIndex, ?callable $isInvalidated = null): ?SwingTrade
    {
        $entryIndex = $signalIndex + 1;

        if (! isset($candles[$entryIndex])) {
            return null; // the signal came on the last bar; there was no session to enter on
        }

        $isBuy = $setup->isBuy();

        // A setup with a trigger is a resting order: it only becomes a trade if price goes
        // on to prove it. Without this, "buy the break above the confirmation high" would
        // be filled at the next open whether or not the break ever happened — which is
        // most of the edge those setups claim, handed over for free.
        if ($setup->entryTrigger !== null) {
            $entryIndex = $this->firstTriggeredBar($setup, $candles, $entryIndex);

            if ($entryIndex === null) {
                return null; // never triggered inside its window; the idea expired untested
            }
        }

        $open = $candles[$entryIndex]['open'];

        // A trigger already exceeded at the open fills at the open, not at the level
        $fill = $setup->entryTrigger !== null
            ? ($isBuy ? max($setup->entryTrigger, $open) : min($setup->entryTrigger, $open))
            : (config('swing.execution.entry') === 'signal_close' ? $setup->signalPrice : $open);

        // The entry itself can gap. Past the configured distance the plan's risk is no
        // longer the risk being taken, so the setup counts as missed rather than chased.
        $reference = $setup->referencePrice();
        $gap = abs($fill - $reference) / $reference * 100;

        if ($gap > config('swing.execution.max_entry_gap_percent')) {
            return null;
        }

        $entry = $this->costs->slip($fill, $isBuy);

        $risk = abs($entry - $setup->stop);

        // The open gapped through the stop: the trade was over before it began
        if ($risk <= 0 || ($isBuy ? $entry <= $setup->stop : $entry >= $setup->stop)) {
            return null;
        }

        $quantity = $this->costs->quantity($entry, $risk);

        if ($quantity < 1) {
            return null;
        }

        return $this->run($setup, $candles, $entryIndex, $entry, $quantity, $risk, $isInvalidated);
    }

    protected function run(SwingSetup $setup, array $candles, int $entryIndex, float $entry, int $quantity, float $risk, ?callable $isInvalidated): SwingTrade
    {
        $isBuy = $setup->isBuy();
        $gapFillAtOpen = config('swing.execution.gap_fill') === 'open';
        $stopFirst = config('swing.exits.same_candle') === 'stop_first';
        $maxDays = config('swing.exits.max_holding_days');
        $scaleOut = (float) config('swing.exits.scale_out_at_target1');

        $remaining = $quantity;
        $target1Quantity = (int) round($quantity * min(1.0, max(0.0, $scaleOut)));
        $stop = $setup->stop;
        $target1Pending = $target1Quantity > 0;
        $target2Pending = $setup->target2 !== null;
        $extreme = $entry; // best price reached since entry, for the chandelier trail
        $exits = [];
        $lastIndex = min($entryIndex + $maxDays - 1, count($candles) - 1);

        for ($d = $entryIndex; $d <= $lastIndex && $remaining > 0; $d++) {
            $bar = $candles[$d];
            $isLastDay = $d === $lastIndex;

            // --- the open ---------------------------------------------------------
            // Skipped on the entry day: the position is opened at that price, so it
            // cannot also gap through a level at the same moment.
            if ($d > $entryIndex) {
                if ($this->breached($bar['open'], $stop, $isBuy)) {
                    $exits[] = new SwingExit($bar['date'], $gapFillAtOpen ? $bar['open'] : $stop, $remaining, 'stop_gap');
                    $remaining = 0;
                    break;
                }

                // A favourable gap fills better than the target, not at it
                if ($target1Pending && $this->reached($bar['open'], $setup->target1, $isBuy)) {
                    $fill = $gapFillAtOpen ? $bar['open'] : $setup->target1;
                    $take = min($target1Quantity, $remaining);
                    $exits[] = new SwingExit($bar['date'], $fill, $take, 'target_gap');
                    $remaining -= $take;
                    $target1Pending = false;
                }

                if ($remaining > 0 && $target2Pending && $this->reached($bar['open'], $setup->target2, $isBuy)) {
                    $exits[] = new SwingExit($bar['date'], $gapFillAtOpen ? $bar['open'] : $setup->target2, $remaining, 'target_gap');
                    $remaining = 0;
                    break;
                }
            }

            // --- inside the session -----------------------------------------------
            $hitStop = $this->breached($isBuy ? $bar['low'] : $bar['high'], $stop, $isBuy);
            $hitTarget1 = $target1Pending && $this->reached($isBuy ? $bar['high'] : $bar['low'], $setup->target1, $isBuy);
            $hitTarget2 = $target2Pending && $this->reached($isBuy ? $bar['high'] : $bar['low'], $setup->target2, $isBuy);

            // A daily candle that reached both cannot say which came first. Assuming the
            // favourable one flatters every result, so the default assumes the stop.
            if ($hitStop && ($hitTarget1 || $hitTarget2) && $stopFirst) {
                $hitTarget1 = $hitTarget2 = false;
            }

            if ($hitTarget1) {
                $take = min($target1Quantity, $remaining);
                $exits[] = new SwingExit($bar['date'], $setup->target1, $take, 'target1');
                $remaining -= $take;
                $target1Pending = false;
            }

            if ($remaining > 0 && $hitTarget2) {
                $exits[] = new SwingExit($bar['date'], $setup->target2, $remaining, 'target2');
                $remaining = 0;
                break;
            }

            if ($remaining > 0 && $hitStop) {
                $reason = $stop === $setup->stop ? 'stop' : 'trailing_stop';
                $exits[] = new SwingExit($bar['date'], $stop, $remaining, $reason);
                $remaining = 0;
                break;
            }

            // --- the close ---------------------------------------------------------
            if ($remaining > 0 && $isInvalidated && $isInvalidated($bar, $d)) {
                $exits[] = new SwingExit($bar['date'], $this->costs->slip($bar['close'], ! $isBuy), $remaining, 'trend_invalidated');
                $remaining = 0;
                break;
            }

            if ($remaining > 0 && $isLastDay) {
                $exits[] = new SwingExit($bar['date'], $this->costs->slip($bar['close'], ! $isBuy), $remaining, 'max_holding_days');
                $remaining = 0;
                break;
            }

            $extreme = $isBuy ? max($extreme, $bar['close']) : min($extreme, $bar['close']);
            $stop = $this->trail($stop, $entry, $extreme, $risk, $bar, $candles, $d, $isBuy);
        }

        // Held days are counted in bars, not calendar days: a trade opened on Friday and
        // closed on Monday was held two sessions, not four days.
        $barsHeld = max(1, $this->exitBar($exits, $candles, $entryIndex) - $entryIndex + 1);

        return $this->settle($setup, $candles[$entryIndex]['date'], $entry, $quantity, $risk, $exits, $barsHeld);
    }

    /** Index of the bar the final exit landed on. */
    protected function exitBar(array $exits, array $candles, int $entryIndex): int
    {
        $lastDate = $exits[array_key_last($exits)]->date;

        for ($i = $entryIndex; $i < count($candles); $i++) {
            if ($candles[$i]['date'] === $lastDate) {
                return $i;
            }
        }

        return $entryIndex;
    }

    /**
     * The first bar on which a resting order would have been filled, or null if the
     * trigger was never reached inside its window.
     *
     * The window matters: an idea that needed a week to prove itself was not the same idea
     * by the time it did, so a trigger that goes untouched expires rather than waiting.
     */
    protected function firstTriggeredBar(SwingSetup $setup, array $candles, int $from): ?int
    {
        $validDays = config('swing.execution.entry_trigger_valid_days', 3);
        $isBuy = $setup->isBuy();
        $last = min($from + $validDays - 1, count($candles) - 1);

        for ($i = $from; $i <= $last; $i++) {
            $reached = $isBuy
                ? $candles[$i]['high'] >= $setup->entryTrigger
                : $candles[$i]['low'] <= $setup->entryTrigger;

            if ($reached) {
                return $i;
            }
        }

        return null;
    }

    /** Has price gone through the stop? Below it on a long, above it on a short. */
    protected function breached(float $price, float $stop, bool $isBuy): bool
    {
        return $isBuy ? $price <= $stop : $price >= $stop;
    }

    /** Has price reached a target? Above it on a long, below it on a short. */
    protected function reached(float $price, float $target, bool $isBuy): bool
    {
        return $isBuy ? $price >= $target : $price <= $target;
    }

    /**
     * Move the stop in the trade's favour, never against it.
     */
    protected function trail(float $stop, float $entry, float $extreme, float $risk, array $bar, array $candles, int $index, bool $isBuy): float
    {
        $trailing = config('swing.exits.trailing');

        $candidate = match ($trailing['method']) {
            // Once the trade has paid for itself, stop risking the original amount
            'breakeven_after_1r' => $this->reached($bar['close'], $isBuy ? $entry + $risk : $entry - $risk, $isBuy)
                ? $entry
                : $stop,

            'atr_chandelier' => $this->chandelier($extreme, $candles, $index, $trailing, $isBuy) ?? $stop,

            default => $stop,
        };

        return $isBuy ? max($stop, $candidate) : min($stop, $candidate);
    }

    protected function chandelier(float $extreme, array $candles, int $index, array $trailing, bool $isBuy): ?float
    {
        // A window rather than the whole series: ATR only needs recent bars, and this is
        // recomputed on every held day of every trade.
        $window = array_slice($candles, max(0, $index - $trailing['atr_period'] * 3), min($index + 1, $trailing['atr_period'] * 3 + 1));
        $atr = $this->indicators->atr($window, $trailing['atr_period']);

        if (! $atr) {
            return null;
        }

        $distance = $atr * $trailing['atr_multiplier'];

        return $isBuy ? $extreme - $distance : $extreme + $distance;
    }

    /**
     * Price the finished trade: gross, charges on every leg, and the result in R.
     */
    protected function settle(SwingSetup $setup, string $entryDate, float $entry, int $quantity, float $risk, array $exits, int $barsHeld): SwingTrade
    {
        $isBuy = $setup->isBuy();
        $gross = 0.0;

        // Charged per order, not per round trip: a scaled-out position pays brokerage on
        // every closing leg, and brokerage is capped per order rather than per trade.
        $costs = $this->costs->legCosts($entry, $quantity, isSell: ! $isBuy);

        foreach ($exits as $exit) {
            $gross += ($isBuy ? $exit->price - $entry : $entry - $exit->price) * $exit->quantity;
            $costs += $this->costs->legCosts($exit->price, $exit->quantity, isSell: $isBuy);
        }

        $net = $gross - $costs;

        return new SwingTrade(
            setup: $setup,
            entryDate: $entryDate,
            entryPrice: $entry,
            quantity: $quantity,
            exits: $exits,
            grossPnl: $gross,
            costs: $costs,
            netPnl: $net,
            // In R: net profit as a multiple of the money actually put at risk
            rMultiple: $net / ($risk * $quantity),
            barsHeld: $barsHeld,
        );
    }
}
