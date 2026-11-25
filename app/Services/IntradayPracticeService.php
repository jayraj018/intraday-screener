<?php

namespace App\Services;

use App\Models\IntradayAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the intraday practice has earned so far, next to random stocks that went through the
 * same entry and exit rules.
 */
class IntradayPracticeService
{
    /**
     * @return array{signal: array, random: array, weeks: int, min_weeks: int, difference: ?array, verdict: string}
     */
    public function report(): array
    {
        $trades = IntradayAlert::orderBy('alert_date')->get(['id', 'kind', 'alert_date', 'status', 'r_multiple', 'net_pnl']);
        $closed = $trades->where('status', '!=', 'OPEN');
        $minWeeks = config('screener.alerts.min_weeks');

        $byWeek = fn (string $kind) => $closed->where('kind', $kind)
            ->groupBy(fn ($t) => Carbon::parse($t->day())->format('o-W'))->map->pluck('r_multiple');

        $signal = $byWeek(IntradayAlert::SIGNAL);
        $random = $byWeek(IntradayAlert::RANDOM);

        $report = [
            'signal' => $this->row($closed->where('kind', IntradayAlert::SIGNAL), $signal, $minWeeks),
            'random' => $this->row($closed->where('kind', IntradayAlert::RANDOM), $random, $minWeeks),
            'weeks' => $signal->count(),
            'min_weeks' => $minWeeks,
            'difference' => ClusteredMean::differenceRange($signal, $random, $minWeeks),
        ];

        return $report + ['verdict' => $this->verdict($report)];
    }

    public function day(string $date): array
    {
        $trades = IntradayAlert::where('alert_date', $date)->get();

        return [
            'signal' => $this->row($trades->where('kind', IntradayAlert::SIGNAL)->where('status', '!=', 'OPEN')),
            'random' => $this->row($trades->where('kind', IntradayAlert::RANDOM)->where('status', '!=', 'OPEN')),
            'open' => $trades->where('status', 'OPEN')->count(),
        ];
    }

    public function summary(string $date): string
    {
        $day = $this->day($date);
        $all = $this->report();
        $line = fn (string $name, array $row) => $row['trades']
            ? sprintf('%s: %d trade(s), %d won, %s after charges', $name, $row['trades'], $row['wins'], $this->money($row['net_pnl']))
            : "{$name}: no trade";
        $soFar = fn (string $name, array $row) => $row['trades']
            ? sprintf('%s: %d trades, %s%% won, %s per trade', $name, $row['trades'], $row['win_rate'], $this->money($row['net_pnl'] / $row['trades']))
            : "{$name}: no trade yet";

        return implode("\n", array_filter([
            '📊 PRACTICE RESULT — ' . Carbon::parse($date)->format('d M Y'),
            $line('Our picks', $day['signal']),
            $line('Random stocks', $day['random']),
            $day['open'] ? "{$day['open']} trade(s) not closed yet: prices are missing." : null,
            '',
            'So far',
            $soFar('Our picks', $all['signal']),
            $soFar('Random stocks', $all['random']),
            $all['verdict'],
            '⚠️ PRACTICE — no real money.',
        ], fn ($l) => $l !== null));
    }

    /** The same wording on the page and in the message, so neither can promise more. */
    protected function verdict(array $report): string
    {
        if ($report['signal']['range'] === null || $report['difference'] === null) {
            return "Too early to judge: {$report['weeks']} of {$report['min_weeks']} weeks.";
        }

        $profitable = $report['signal']['range'][0] > 0;
        $better = $report['difference'][0] > 0;

        return match (true) {
            $profitable && $better => 'Profitable and better than random so far. Not a promise.',
            $profitable => 'Profitable, but not clearly better than random: no proof the picking works.',
            $better => 'Better than random, but not clearly profitable after charges.',
            default => 'Not clearly profitable and not clearly better than random. Do not trade this.',
        };
    }

    protected function row(Collection $closed, ?Collection $weeks = null, int $minWeeks = 0): array
    {
        $count = $closed->count();
        $wins = $closed->filter(fn ($t) => $t->net_pnl > 0)->count();

        return [
            'trades' => $count,
            'wins' => $wins,
            'win_rate' => $count ? round($wins / $count * 100) : null,
            'average_r' => $count ? round($closed->avg('r_multiple'), 3) : null,
            'net_pnl' => round((float) $closed->sum('net_pnl'), 2),
            'range' => $weeks?->isNotEmpty() ? ClusteredMean::range($weeks, $minWeeks) : null,
        ];
    }

    protected function money(float $value): string
    {
        return ($value < 0 ? '−' : '+') . '₹' . number_format(abs($value));
    }
}
