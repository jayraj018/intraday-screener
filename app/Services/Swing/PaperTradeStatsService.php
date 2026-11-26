<?php

namespace App\Services\Swing;

use App\Models\Candle;
use App\Models\SwingPaperTrade;
use App\Services\ClusteredMean;
use Illuminate\Support\Collection;

/**
 * What the paper log says so far, counted the way the backtest counts.
 */
class PaperTradeStatsService
{
    /**
     * @return array{label: string, late: int, rows: array<int, array>, benchmark: ?array}
     */
    public function report(): array
    {
        $trades = SwingPaperTrade::query()->orderBy('signal_date')->orderBy('id')
            ->get(['id', 'kind', 'symbol', 'strategy', 'signal_date', 'exit_date', 'late', 'portfolio', 'status', 'r_multiple', 'net_pnl']);

        $counted = $trades->where('late', false);
        $signals = $counted->where('kind', SwingPaperTrade::SIGNAL);

        // Already one position per stock, so nothing is dropped a second time
        $rows = [$this->row('Paper account (within limits)', $signals->where('portfolio', 'TAKEN'))];
        // Watch-only strategies are measured on their own rows, not mixed into the trusted total
        $rows[] = $this->row('All signals (not watch-only)', $this->onePerStock(
            $signals->reject(fn ($t) => SwingTrust::isWatchOnly($t->strategy))
        ));

        foreach ($signals->groupBy('strategy')->sortKeys() as $strategy => $group) {
            $name = SwingTrust::isWatchOnly((string) $strategy) ? "{$strategy} (watch only)" : (string) $strategy;
            $rows[] = $this->row($name, $this->onePerStock($group));
        }

        $rows[] = $this->row('Random entries (comparison)', $this->onePerStock($counted->where('kind', SwingPaperTrade::RANDOM)));

        return [
            'label' => 'PAPER — no money was traded',
            'late' => $trades->where('late', true)->count(),
            'rows' => $rows,
            'benchmark' => $this->benchmark($signals->where('portfolio', 'TAKEN')),
        ];
    }

    /**
     * The paper account against simply holding NIFTY 50 (what NIFTYBEES tracks) from the day
     * the account first took a plan. In the NSE research, buying the index beat every swing
     * rule but one, so a strategy has to clear this as well as the random entries.
     *
     * Read from stored candles only; null until there is something to compare.
     *
     * @return ?array{since: string, until: string, account_percent: float, nifty_percent: float, closed: int}
     */
    public function benchmark(Collection $taken): ?array
    {
        $start = $taken->min('signal_date');

        if (! $start) {
            return null;
        }

        $nifty = Candle::where('symbol', config('swing.regime.benchmark'))->where('interval', '1d');
        $from = (clone $nifty)->whereDate('date', '<=', $start->toDateString())->orderByDesc('date')->first();
        $to = (clone $nifty)->orderByDesc('date')->first();

        if (! $from || ! $to || $from->close <= 0) {
            return null;
        }

        $closed = $taken->where('status', SwingPaperTrade::CLOSED);

        return [
            'since' => $from->date->toDateString(),
            'until' => $to->date->toDateString(),
            // Closed trades only: an open position's result is not known yet
            'account_percent' => round((float) $closed->sum('net_pnl') / config('swing.costs.capital') * 100, 2),
            'nifty_percent' => round(($to->close / $from->close - 1) * 100, 2),
            'closed' => $closed->count(),
        ];
    }

    /**
     * Drop any plan on a stock that was still in an earlier trade. Five strategies firing on
     * one move are one outcome, and counting it five times would look like evidence.
     */
    protected function onePerStock(Collection $trades): Collection
    {
        $busyUntil = [];

        return $trades->filter(function (SwingPaperTrade $trade) use (&$busyUntil) {
            $free = ($busyUntil[$trade->symbol] ?? '') < $trade->signal_date->toDateString();

            if ($free && $trade->status !== SwingPaperTrade::NO_ENTRY) {
                $busyUntil[$trade->symbol] = $trade->exit_date?->toDateString() ?? '9999-12-31';
            }

            return $free;
        });
    }

    protected function row(string $name, Collection $trades): array
    {
        $closed = $trades->where('status', SwingPaperTrade::CLOSED);
        $results = $closed->pluck('r_multiple');
        $gains = $results->filter(fn ($r) => $r > 0)->sum();
        $losses = -$results->filter(fn ($r) => $r < 0)->sum();
        $count = $closed->count();

        return [
            'name' => $name,
            'logged' => $trades->count(),
            'waiting' => $trades->where('status', SwingPaperTrade::PENDING)->count(),
            'open' => $trades->where('status', SwingPaperTrade::OPEN)->count(),
            'not_entered' => $trades->where('status', SwingPaperTrade::NO_ENTRY)->count(),
            'closed' => $count,
            'win_rate' => $count ? round($results->filter(fn ($r) => $r > 0)->count() / $count * 100, 1) : null,
            'average_r' => $count ? round($results->avg(), 3) : null,
            'total_r' => $count ? round($results->sum(), 2) : null,
            'profit_factor' => $losses > 0 ? round($gains / $losses, 2) : null,
            'net_pnl' => round((float) $closed->sum('net_pnl'), 2),
            'range' => $this->range($closed),
        ];
    }

    /**
     * 95% range of the average R, treating each signal week as one observation: trades opened
     * in the same week rise and fall together, so counting them separately would make the
     * range far narrower than the evidence is.
     *
     * @return ?array{0: float, 1: float} null until there are enough weeks to say anything
     */
    protected function range(Collection $closed): ?array
    {
        return ClusteredMean::range(
            $closed->groupBy(fn ($t) => $t->signal_date->format('o-W'))->map->pluck('r_multiple'),
            config('swing.paper.min_weeks'),
        );
    }
}
