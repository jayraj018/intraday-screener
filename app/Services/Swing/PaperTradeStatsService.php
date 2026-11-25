<?php

namespace App\Services\Swing;

use App\Models\SwingPaperTrade;
use Illuminate\Support\Collection;

/**
 * What the paper log says so far, counted the way the backtest counts.
 */
class PaperTradeStatsService
{
    /**
     * @return array{label: string, late: int, rows: array<int, array>}
     */
    public function report(): array
    {
        $trades = SwingPaperTrade::query()->orderBy('signal_date')->orderBy('id')
            ->get(['id', 'kind', 'symbol', 'strategy', 'signal_date', 'exit_date', 'late', 'portfolio', 'status', 'r_multiple', 'net_pnl']);

        $counted = $trades->where('late', false);
        $signals = $counted->where('kind', SwingPaperTrade::SIGNAL);

        // Already one position per stock, so nothing is dropped a second time
        $rows = [$this->row('Paper account (within limits)', $signals->where('portfolio', 'TAKEN'))];
        $rows[] = $this->row('All signals', $this->onePerStock($signals));

        foreach ($signals->groupBy('strategy')->sortKeys() as $strategy => $group) {
            $rows[] = $this->row((string) $strategy, $this->onePerStock($group));
        }

        $rows[] = $this->row('Random entries (comparison)', $this->onePerStock($counted->where('kind', SwingPaperTrade::RANDOM)));

        return [
            'label' => 'PAPER — no money was traded',
            'late' => $trades->where('late', true)->count(),
            'rows' => $rows,
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
        $weeks = $closed->groupBy(fn ($t) => $t->signal_date->format('o-W'));
        $g = $weeks->count();

        if ($g < config('swing.paper.min_weeks')) {
            return null;
        }

        $mean = $closed->avg('r_multiple');
        $squares = $weeks->sum(fn ($week) => $week->sum(fn ($t) => $t->r_multiple - $mean) ** 2);
        $error = sqrt($squares * $g / ($g - 1)) / $closed->count();

        return [round($mean - 1.96 * $error, 3), round($mean + 1.96 * $error, 3)];
    }
}
