<?php

namespace App\Services\Swing;

/**
 * Everything the seven swing strategies read, computed once per symbol per decision bar.
 *
 * Built rather than fetched inside each strategy for two reasons. The expensive parts —
 * the market regime above all — are shared, and recomputing them seven times per stock
 * would dominate a walk-forward run. More importantly, a strategy handed a finished
 * context cannot reach for a candle that has not printed: the series it receives already
 * ends at the bar being judged.
 */
final class SwingContext
{
    public function __construct(
        public readonly string $symbol,
        /** Daily candles, ending at the decision bar */
        public readonly array $daily,
        /** Weekly candles over the same history, for broader context */
        public readonly array $weekly,
        public readonly array $regime,
        public readonly array $relativeStrength,
        public readonly string $weeklyTrend,   // UP | DOWN | UNKNOWN
        public readonly ?float $atr,
        public readonly ?array $adx,
        public readonly ?float $bollingerWidth,
        public readonly ?float $averageVolume,
    ) {
    }

    /** The bar being judged. Everything else in this object describes the run up to it. */
    public function bar(): array
    {
        return $this->daily[array_key_last($this->daily)];
    }

    public function close(): float
    {
        return $this->bar()['close'];
    }

    public function date(): string
    {
        return $this->bar()['date'];
    }

    public function closes(): array
    {
        return array_column($this->daily, 'close');
    }

    /** Relative volume on the decision bar: 1.0 is an average day. */
    public function relativeVolume(): ?float
    {
        return $this->averageVolume > 0 ? round($this->bar()['volume'] / $this->averageVolume, 2) : null;
    }

    public function marketIsTrendingUp(): bool
    {
        return $this->regime['trend'] === 'BULLISH_TREND';
    }

    public function marketIsSideways(): bool
    {
        return $this->regime['trend'] === 'SIDEWAYS';
    }

    /** Outpaced the benchmark over every span that could be measured. */
    public function isLeadingTheMarket(): bool
    {
        return $this->relativeStrength['leading'];
    }

    public function weeklyTrendIsUp(): bool
    {
        return $this->weeklyTrend === 'UP';
    }

    /** ATR as a percentage of price — the form worth comparing between stocks. */
    public function atrPercent(): ?float
    {
        return $this->atr && $this->close() > 0 ? round($this->atr / $this->close() * 100, 3) : null;
    }
}
