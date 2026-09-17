<?php

namespace App\Services;

/**
 * Position sizing and the charges that come with a trade.
 *
 * The backtest used to call a trade a win whenever the close finished above the entry, by
 * any amount. A ₹0.01 gain is a loss once the round trip has been paid for, and on a
 * strategy whose edge is small that misclassification decides whether it looks profitable.
 *
 * Rates live in config and differ by system: intraday settles as MIS (STT 0.025%, sell
 * leg only), swing settles as delivery (STT 0.1% on both legs — roughly eight times the
 * tax). Call withRates('swing') to charge a held position properly.
 */
class TradeCostService
{
    protected array $config;

    protected float $slippageBps;

    public function __construct()
    {
        $this->config = config('screener.costs');
        $this->slippageBps = config('screener.execution.slippage_bps');
    }

    /**
     * A copy charging another system's rates. Immutable, so the injected singleton keeps
     * serving intraday unchanged.
     */
    public function withRates(string $namespace): static
    {
        $clone = clone $this;
        $clone->config = config("{$namespace}.costs");
        $clone->slippageBps = config("{$namespace}.execution.slippage_bps");

        return $clone;
    }

    /**
     * Shares to trade, sized from the risk budget rather than a fixed lot: risking a
     * fixed fraction of capital is what makes R multiples comparable across stocks.
     *
     * Zero when the stop is so far away that a single share would breach the budget —
     * that trade genuinely couldn't be taken, so it shouldn't be counted.
     */
    public function quantity(float $entry, float $riskPerShare): int
    {
        if ($riskPerShare <= 0 || $entry <= 0) {
            return 0;
        }

        $riskBudget = $this->config['capital'] * $this->config['risk_per_trade_percent'] / 100;
        $quantity = (int) floor($riskBudget / $riskPerShare);

        // A position can still be too large for the account even when the risk is small
        $affordable = (int) floor($this->config['max_position_value'] / $entry);

        return max(0, min($quantity, $affordable));
    }

    /**
     * Charges on a single executed order.
     *
     * Per leg rather than per round trip, because a swing position can be scaled out in
     * more than one exit, and brokerage is charged — and capped — per order.
     */
    public function legCosts(float $price, int $quantity, bool $isSell): float
    {
        if ($quantity < 1) {
            return 0.0;
        }

        $value = $price * $quantity;

        $brokerage = min($value * $this->config['brokerage_percent'] / 100, $this->config['brokerage_cap']);
        $exchange = $value * $this->config['exchange_txn_percent'] / 100;
        $sebi = $value * $this->config['sebi_percent'] / 100;

        $stt = $value * ($isSell
            ? $this->config['stt_sell_percent']
            : ($this->config['stt_buy_percent'] ?? 0)) / 100;

        $stamp = $isSell ? 0.0 : $value * $this->config['stamp_duty_buy_percent'] / 100;

        // GST applies to the broker's and exchange's fees, not to the taxes
        $gst = ($brokerage + $exchange + $sebi) * $this->config['gst_percent'] / 100;

        return round($brokerage + $exchange + $sebi + $stt + $stamp + $gst, 2);
    }

    /**
     * Total charges for a simple one-in, one-out trade.
     */
    public function roundTripCosts(float $entry, float $exit, int $quantity, bool $isBuyFirst): float
    {
        if ($quantity < 1) {
            return 0.0;
        }

        return round(
            $this->legCosts($entry, $quantity, isSell: ! $isBuyFirst)
            + $this->legCosts($exit, $quantity, isSell: $isBuyFirst),
            2
        );
    }

    /**
     * Move a fill against the trader by the configured slippage. A backtest that fills
     * every order at the exact printed price is describing a market with no spread.
     */
    public function slip(float $price, bool $buying): float
    {
        $bps = $this->slippageBps / 10000;

        return $buying ? $price * (1 + $bps) : $price * (1 - $bps);
    }
}
