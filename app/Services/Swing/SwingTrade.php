<?php

namespace App\Services\Swing;

/**
 * A completed swing trade: what was planned, what was actually paid, and what came back.
 *
 * Signal price, entry price and exit price are kept apart on purpose. A swing position is
 * exposed overnight, so the entry is never the signal price, and collapsing the two is
 * what let the old intraday backtest book the overnight gap as free profit.
 */
final class SwingTrade
{
    /** @param array<int, SwingExit> $exits */
    public function __construct(
        public readonly SwingSetup $setup,
        public readonly string $entryDate,
        public readonly float $entryPrice,
        public readonly int $quantity,
        public readonly array $exits,
        public readonly float $grossPnl,
        public readonly float $costs,
        public readonly float $netPnl,
        public readonly float $rMultiple,
        public readonly int $barsHeld,
    ) {
    }

    /** Volume-weighted average of every closing order. */
    public function exitPrice(): float
    {
        $value = array_sum(array_map(fn (SwingExit $e) => $e->price * $e->quantity, $this->exits));

        return $this->quantity > 0 ? round($value / $this->quantity, 4) : 0.0;
    }

    public function exitDate(): string
    {
        return $this->lastExit()->date;
    }

    /** What finally closed the position, which is the reason worth reporting. */
    public function exitReason(): string
    {
        return $this->lastExit()->reason;
    }

    /**
     * end() takes its argument by reference, which a readonly property cannot provide,
     * so the final leg is addressed by key instead.
     */
    protected function lastExit(): SwingExit
    {
        return $this->exits[array_key_last($this->exits)];
    }

    /**
     * The row shape backtest_trades stores, shared with the intraday system so both can
     * be aggregated by the same metrics service — kept apart only by `system`.
     */
    public function toArray(): array
    {
        return [
            'system' => 'swing',
            'symbol' => $this->setup->symbol,
            'strategy' => $this->setup->strategy,
            'direction' => $this->setup->direction,
            'signal_date' => $this->setup->signalDate,
            'signal_price' => round($this->setup->signalPrice, 4),
            'entry_date' => $this->entryDate,
            'entry_price' => round($this->entryPrice, 4),
            'exit_date' => $this->exitDate(),
            'exit_price' => $this->exitPrice(),
            'exit_reason' => $this->exitReason(),
            'quantity' => $this->quantity,
            'gross_pnl' => round($this->grossPnl, 2),
            'costs' => round($this->costs, 2),
            'net_pnl' => round($this->netPnl, 2),
            'r_multiple' => round($this->rMultiple, 4),
            'bars_held' => $this->barsHeld,
        ];
    }
}
