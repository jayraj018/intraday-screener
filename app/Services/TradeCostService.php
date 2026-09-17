<?php

namespace App\Services;

/**
 * Position sizing and the charges that come with an Indian intraday equity trade.
 *
 * The backtest used to call a trade a win whenever the close finished above the entry,
 * by any amount. A ₹0.01 gain is a loss once the round trip has been paid for, and on a
 * strategy whose edge is small that misclassification decides whether it looks
 * profitable. Everything here is configurable in config/screener.php — nothing about
 * these rates is a law of nature and they change.
 */
class TradeCostService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('screener.costs');
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
     * Total round-trip charges in rupees.
     *
     * Brokerage is per executed order and capped, so it is charged per leg rather than
     * on combined turnover — at small position sizes the cap is most of the difference.
     */
    public function roundTripCosts(float $entry, float $exit, int $quantity, bool $isBuyFirst): float
    {
        if ($quantity < 1) {
            return 0.0;
        }

        $entryValue = $entry * $quantity;
        $exitValue = $exit * $quantity;

        // Whichever leg is the sale carries STT; the purchase carries stamp duty
        $sellValue = $isBuyFirst ? $exitValue : $entryValue;
        $buyValue = $isBuyFirst ? $entryValue : $exitValue;

        $brokerage = $this->brokerage($entryValue) + $this->brokerage($exitValue);
        $exchange = ($entryValue + $exitValue) * $this->config['exchange_txn_percent'] / 100;
        $sebi = ($entryValue + $exitValue) * $this->config['sebi_percent'] / 100;
        $stt = $sellValue * $this->config['stt_sell_percent'] / 100;
        $stamp = $buyValue * $this->config['stamp_duty_buy_percent'] / 100;
        $gst = ($brokerage + $exchange + $sebi) * $this->config['gst_percent'] / 100;

        return round($brokerage + $exchange + $sebi + $stt + $stamp + $gst, 2);
    }

    protected function brokerage(float $orderValue): float
    {
        return min($orderValue * $this->config['brokerage_percent'] / 100, $this->config['brokerage_cap']);
    }

    /**
     * Move a fill against the trader by the configured slippage. A backtest that fills
     * every order at the exact printed price is describing a market with no spread.
     */
    public function slip(float $price, bool $buying): float
    {
        $bps = config('screener.execution.slippage_bps') / 10000;

        return $buying ? $price * (1 + $bps) : $price * (1 - $bps);
    }
}
