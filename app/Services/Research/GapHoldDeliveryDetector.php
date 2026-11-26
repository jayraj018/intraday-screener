<?php

namespace App\Services\Research;

use App\Models\NseDailyBar;
use Illuminate\Support\Collection;

/**
 * The research's Gap-and-Hold with Delivery pattern (H8 / R3), evaluated at a session's close
 * from stored NSE bars up to and including that session — nothing later.
 *
 *   open  >= 1.02 x previous close        (a real gap up)
 *   close >= open                         (the gap held: no fill during the day)
 *   close location >= 0.6                 (closed in the top 40% of the range)
 *   volume >= 2.0 x 20-session average    (average excludes the day itself)
 *   delivery % >= its 20-session average  (more of the buying was taken into demat than usual)
 *
 * Universe: EQ series, close >= Rs 20, 20-session median turnover >= Rs 5 crore, enough history,
 * no corporate-action-sized jump (> 22% open gap) inside the window, which would distort ATR.
 */
class GapHoldDeliveryDetector
{
    /**
     * @param  ?array<int, string>  $symbols  restrict to these (e.g. an index list); null = all
     * @return array{date: ?string, matches: array, near_misses: array, evaluated: int, skipped: array}
     */
    public function scan(?string $date = null, ?array $symbols = null): array
    {
        $cfg = config('research.gap_hold_delivery');
        $date ??= NseDailyBar::max('date');

        if (! $date) {
            return ['date' => null, 'matches' => [], 'near_misses' => [], 'evaluated' => 0, 'skipped' => ['no_data' => 1]];
        }

        $dates = NseDailyBar::where('date', '<=', $date)->distinct()->orderByDesc('date')->limit($cfg['min_history'] + 5)->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->reverse()->values();

        // Matches and near misses both need the gap, so only that day's EQ gap-ups are loaded with
        // their history (prefiltered loosely on NSE's prev close; the exact test is below).
        $today = NseDailyBar::where('date', $date)->where('series', 'EQ');

        if ($symbols) {
            $today->whereIn('symbol', $symbols);
        }

        $universe = (clone $today)->count();
        $candidates = $today->whereRaw('open >= prev_close * ?', [1 + $cfg['min_gap'] - 0.005])->pluck('symbol');

        $bySymbol = NseDailyBar::whereIn('date', $dates)->whereIn('symbol', $candidates)->orderBy('date')->toBase()->get()
            ->map(function ($r) {
                foreach (['prev_close', 'open', 'high', 'low', 'close', 'turnover', 'deliv_pct'] as $k) {
                    $r->$k = $r->$k === null ? null : (float) $r->$k;
                }
                $r->volume = (int) $r->volume;
                $r->date = substr((string) $r->date, 0, 10);

                return $r;
            })->groupBy('symbol');
        $matches = $near = [];
        $skipped = ['no_gap_up_today' => max(0, $universe - $candidates->count())];
        $evaluated = 0;

        foreach ($bySymbol as $symbol => $bars) {
            $result = $this->evaluate($symbol, $bars->values(), $date, $cfg);

            if (isset($result['skip'])) {
                $skipped[$result['skip']] = ($skipped[$result['skip']] ?? 0) + 1;

                continue;
            }

            $evaluated++;
            $passed = count(array_filter($result['conditions'], fn ($c) => $c['pass']));

            if ($passed === count($result['conditions'])) {
                $matches[] = $result;
            } elseif ($passed >= count($result['conditions']) - 1 && $result['conditions'][0]['pass']) {
                $near[] = $result;  // gapped up and missed exactly one other condition
            }
        }

        usort($matches, fn ($a, $b) => $b['relative_volume'] <=> $a['relative_volume']);
        usort($near, fn ($a, $b) => $b['relative_volume'] <=> $a['relative_volume']);

        return ['date' => $date, 'matches' => $matches, 'near_misses' => array_slice($near, 0, 25), 'evaluated' => $evaluated, 'skipped' => $skipped];
    }

    public function evaluate(string $symbol, Collection $bars, string $date, array $cfg): array
    {
        $last = $bars->last();

        if (! $last || substr((string) $last->date, 0, 10) !== $date) {
            return ['skip' => 'did_not_trade'];
        }

        if ($last->series !== 'EQ') {
            return ['skip' => 'not_eq_series'];
        }

        $n = $bars->count();
        $lookback = $cfg['volume_lookback'];

        if ($n < $cfg['min_history']) {
            return ['skip' => 'short_history'];
        }

        $prior = $bars->slice($n - 1 - $lookback, $lookback);
        $turnovers = $bars->slice($n - $lookback)->pluck('turnover')->sort()->values();
        $medianTurnover = ($turnovers[intdiv($lookback - 1, 2)] + $turnovers[intdiv($lookback, 2)]) / 2;

        if ($last->close < $cfg['min_price'] || $medianTurnover < $cfg['min_median_turnover']) {
            return ['skip' => 'illiquid_or_low_price'];
        }

        for ($i = 1; $i < $n; $i++) {
            if (abs($bars[$i]->open / $bars[$i - 1]->close - 1) > 0.22) {
                return ['skip' => 'corporate_action_in_window'];
            }
        }

        $prev = $bars[$n - 2];
        $avgVolume = $prior->avg('volume');
        $delivPrior = $prior->pluck('deliv_pct')->filter(fn ($v) => $v !== null);
        $avgDeliv = $delivPrior->count() >= 15 ? $delivPrior->avg() : null;
        $range = $last->high - $last->low;
        $clv = $range > 0 ? ($last->close - $last->low) / $range : 0.5;
        $gap = $last->open / $prev->close - 1;
        $rvol = $avgVolume > 0 ? $last->volume / $avgVolume : 0;
        $atr = $this->atr($bars, $cfg['atr_period']);

        $conditions = [
            ['label' => 'Gap up >= 2%', 'pass' => $gap >= $cfg['min_gap'],
                'detail' => sprintf('Opened %.2f vs previous close %.2f (%+.2f%%)', $last->open, $prev->close, $gap * 100)],
            ['label' => 'Gap held (close >= open)', 'pass' => $last->close >= $last->open,
                'detail' => sprintf('Close %.2f vs open %.2f', $last->close, $last->open)],
            ['label' => 'Closed in top 40% of range', 'pass' => $clv >= $cfg['min_clv'],
                'detail' => sprintf('Close location %.2f (needs >= %.1f)', $clv, $cfg['min_clv'])],
            ['label' => 'Volume >= 2x 20-day average', 'pass' => $rvol >= $cfg['min_relative_volume'],
                'detail' => sprintf('%.2fx (%s vs %s avg, today excluded)', $rvol, number_format($last->volume), number_format($avgVolume))],
            ['label' => 'Delivery % >= its 20-day average', 'pass' => $avgDeliv !== null && $last->deliv_pct !== null && $last->deliv_pct >= $avgDeliv,
                'detail' => $avgDeliv === null || $last->deliv_pct === null ? 'Delivery data missing — condition cannot pass'
                    : sprintf('%.1f%% vs %.1f%% average', $last->deliv_pct, $avgDeliv)],
        ];

        $stop = $last->close - $cfg['stop_atr'] * $atr;
        $riskPerShare = $last->close - $stop;
        $riskBudget = $cfg['capital'] * $cfg['risk_percent'] / 100;
        $qty = $riskPerShare > 0 ? (int) floor(min($riskBudget / $riskPerShare, $cfg['capital'] / 5 / $last->close)) : 0;

        return [
            'symbol' => $symbol,
            'date' => $date,
            'conditions' => $conditions,
            'relative_volume' => round($rvol, 2),
            'close' => $last->close,
            'atr' => round($atr, 4),
            'plan' => [
                'entry' => 'Next session open, only if it is no more than 2% above ' . number_format($last->close, 2),
                'max_entry' => round($last->close * (1 + $cfg['max_entry_gap']), 2),
                'stop' => round($stop, 2),
                'reference_target' => round($last->close + $cfg['reference_target_r'] * $riskPerShare, 2),
                'reward_to_risk' => $cfg['reference_target_r'],
                'risk_percent' => round($riskPerShare / $last->close * 100, 2),
                'exit_rule' => sprintf('Trail the stop %.0f x ATR below the highest close; exit at the close of session %d at the latest. The 2R level is a reference, not an order.', $cfg['trail_atr'], $cfg['max_hold']),
                'quantity_for_capital' => $qty,
            ],
        ];
    }

    /** Wilder ATR over the stored bars. */
    public function atr(Collection $bars, int $period): float
    {
        $tr = [];

        for ($i = 1; $i < $bars->count(); $i++) {
            $b = $bars[$i];
            $pc = $bars[$i - 1]->close;
            $tr[] = max($b->high - $b->low, abs($b->high - $pc), abs($b->low - $pc));
        }

        if (count($tr) < $period) {
            return 0.0;
        }

        $atr = array_sum(array_slice($tr, 0, $period)) / $period;

        for ($i = $period; $i < count($tr); $i++) {
            $atr = ($atr * ($period - 1) + $tr[$i]) / $period;
        }

        return $atr;
    }
}
