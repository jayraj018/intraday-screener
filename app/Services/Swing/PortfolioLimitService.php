<?php

namespace App\Services\Swing;

use App\Models\SwingPaperTrade;
use App\Services\TradeCostService;
use Illuminate\Support\Collection;

/**
 * Which plans the account has room for, taken best first.
 *
 * A resting order counts as a position: it can fill without anybody deciding anything
 * further, so a slot promised to it is a slot used.
 */
class PortfolioLimitService
{
    protected TradeCostService $costs;

    public function __construct(TradeCostService $costs, protected SectorService $sectors)
    {
        $this->costs = $costs->withRates('swing');
    }

    /** @return array{quantity: int, value: float, risk: float} */
    public function size(float $entry, float $stop): array
    {
        $risk = abs($entry - $stop);
        $quantity = $this->costs->quantity($entry, $risk);

        return ['quantity' => $quantity, 'value' => round($quantity * $entry, 2), 'risk' => round($quantity * $risk, 2)];
    }

    /**
     * @param  iterable<int, array{symbol: string, entry: float, stop: float}>  $plans  best first
     * @param  ?Collection<int, SwingPaperTrade>  $held  what the account already has on
     * @return array<string, array{take: bool, reason: string, quantity: int, value: float, risk: float}> by symbol
     */
    public function decide(iterable $plans, ?Collection $held = null): array
    {
        $held ??= SwingPaperTrade::held()->get();
        $limits = config('swing.portfolio');
        $capital = config('swing.costs.capital');
        $maxRisk = $capital * $limits['max_open_risk_percent'] / 100;
        $maxValue = $capital * $limits['max_exposure_percent'] / 100;

        $symbols = $held->pluck('symbol')->all();
        $maxPerSector = $limits['max_per_sector'] ?? null;

        // Five bank stocks are one bet on banks, not five trades
        $perSector = [];

        foreach ($symbols as $symbol) {
            if ($sector = $this->sectors->of($symbol)) {
                $perSector[$sector] = ($perSector[$sector] ?? 0) + 1;
            }
        }

        $count = $held->count();
        $risk = (float) $held->sum('planned_risk');
        $value = (float) $held->sum('planned_value');
        $decisions = [];

        foreach ($plans as $plan) {
            if (isset($decisions[$plan['symbol']])) {
                continue;
            }

            $size = $this->size($plan['entry'], $plan['stop']);
            $sector = $this->sectors->of($plan['symbol']);

            $refusal = match (true) {
                $size['quantity'] < 1 => 'One share already risks more than the limit per trade',
                in_array($plan['symbol'], $symbols, true) => 'Already holding this stock',
                $count >= $limits['max_positions'] => sprintf('All %d positions are in use', $limits['max_positions']),
                $sector && $maxPerSector && ($perSector[$sector] ?? 0) >= $maxPerSector => sprintf('Already %d in %s, the most allowed in one sector', $maxPerSector, $sector),
                $risk + $size['risk'] > $maxRisk => sprintf('Open risk would be %.1f%%, above the %.1f%% limit', ($risk + $size['risk']) / $capital * 100, $limits['max_open_risk_percent']),
                $value + $size['value'] > $maxValue => sprintf('Needs ₹%s, only ₹%s is free', number_format($size['value']), number_format(max(0, $maxValue - $value))),
                default => null,
            };

            if ($refusal === null) {
                $symbols[] = $plan['symbol'];

                if ($sector) {
                    $perSector[$sector] = ($perSector[$sector] ?? 0) + 1;
                }

                $count++;
                $risk += $size['risk'];
                $value += $size['value'];
            }

            $decisions[$plan['symbol']] = [
                'take' => $refusal === null,
                'reason' => $refusal ?? sprintf('Position %d of %d, open risk %.1f%%', $count, $limits['max_positions'], $risk / $capital * 100),
            ] + $size;
        }

        return $decisions;
    }
}
