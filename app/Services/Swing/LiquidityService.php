<?php

namespace App\Services\Swing;

use App\Services\TradeCostService;

/**
 * Whether a stock can actually be traded at the size the risk model asks for.
 *
 * This was the gap that could lose real money. Position size is decided by risk —
 * budget divided by the distance to the stop — and a tight stop produces a large
 * position. Nothing was checking whether the stock trades enough for that position to
 * be entered, carried for days, and exited without moving the price against itself.
 *
 * Turnover matters as much as share count: 500,000 shares a day of a ₹15 stock is
 * ₹75 lakh of real liquidity, not a liquid market.
 */
class LiquidityService
{
    public function __construct(protected TradeCostService $costs)
    {
    }

    /**
     * @param  ?float  $riskPerShare  when known, the intended position is checked against
     *                                a normal day's volume as well
     * @return array{tradeable: bool, reasons: array<int, string>, average_volume: float, average_turnover: float, position_percent: ?float}
     */
    public function assess(SwingContext $context, ?float $riskPerShare = null): array
    {
        $config = config('swing.liquidity');
        $price = $context->close();
        $averageVolume = $context->averageVolume ?? 0.0;
        $averageTurnover = $averageVolume * $price;

        $reasons = [];

        if ($price < $config['min_price']) {
            $reasons[] = sprintf('Price ₹%.2f is below the ₹%.2f floor', $price, $config['min_price']);
        }

        if ($averageVolume < $config['min_average_volume']) {
            $reasons[] = sprintf('%s shares/day average, below %s', number_format($averageVolume), number_format($config['min_average_volume']));
        }

        if ($averageTurnover < $config['min_average_turnover']) {
            $reasons[] = sprintf('₹%s of turnover a day, below ₹%s', number_format($averageTurnover / 10000000, 2) . ' crore', number_format($config['min_average_turnover'] / 10000000, 2) . ' crore');
        }

        // The position the risk model would actually ask for, against a normal day
        $positionPercent = null;

        if ($riskPerShare > 0 && $averageVolume > 0) {
            $quantity = $this->costs->withRates('swing')->quantity($price, $riskPerShare);
            $positionPercent = round($quantity / $averageVolume * 100, 3);

            if ($positionPercent > $config['max_percent_of_daily_volume']) {
                $reasons[] = sprintf(
                    'The %s-share position is %.2f%% of a normal day, above the %.1f%% limit',
                    number_format($quantity), $positionPercent, $config['max_percent_of_daily_volume']
                );
            }
        }

        return [
            'tradeable' => $reasons === [],
            'reasons' => $reasons,
            'average_volume' => round($averageVolume),
            'average_turnover' => round($averageTurnover),
            'position_percent' => $positionPercent,
        ];
    }
}
