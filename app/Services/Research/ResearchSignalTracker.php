<?php

namespace App\Services\Research;

use App\Models\NseDailyBar;
use App\Models\ResearchSignal;
use Illuminate\Support\Carbon;

/**
 * Forward (live) evidence for the research pattern, kept apart from the backtest.
 *
 * A signal is written down at its session's close and resolved later, bar by bar, by the same
 * rule the research engine used (research/src/engine.py, template E2): enter at the next open
 * plus slippage unless it opens more than 2% above the signal close or at/below the stop; a gap
 * through the stop fills at the open; an intraday stop fills 25 bps beyond it; the stop trails
 * 3 x ATR below the highest close; the position is closed at the close of session 20.
 * Charges are the research's delivery charges on a Rs 10,000 position.
 */
class ResearchSignalTracker
{
    public const PATTERN = 'gap_hold_delivery';

    public function __construct(protected GapHoldDeliveryDetector $detector)
    {
    }

    /** @return int signals newly recorded */
    public function record(array $scan): int
    {
        $cfg = config('research.gap_hold_delivery');
        $count = 0;

        foreach ($scan['matches'] as $m) {
            $signal = ResearchSignal::firstOrCreate(
                ['pattern' => self::PATTERN, 'symbol' => $m['symbol'], 'signal_date' => $m['date']],
                [
                    'logged_at' => now(),
                    // logged once the next session had already opened (09:15 IST): may have been
                    // chosen by the price it claims to predict, so it is kept but never counted
                    'late' => $this->isLate($m['date']),
                    'signal_close' => $m['close'],
                    'atr' => $m['atr'],
                    'stop_price' => $m['plan']['stop'],
                    'reference_target' => $m['plan']['reference_target'],
                    'conditions' => $m['conditions'],
                    'rules' => array_intersect_key($cfg, array_flip(['stop_atr', 'trail_atr', 'max_hold', 'max_entry_gap', 'slippage', 'stop_slippage'])),
                ],
            );
            $count += $signal->wasRecentlyCreated ? 1 : 0;
        }

        return $count;
    }

    protected function isLate(string $signalDate): bool
    {
        $next = NseDailyBar::where('date', '>', $signalDate)->min('date');

        if ($next) {
            return true;
        }

        $now = now('Asia/Kolkata');

        return $now->toDateString() > $signalDate && ! $now->isWeekend() && $now->format('H:i') >= '09:15';
    }

    /** @return array{resolved: int, still_open: int} */
    public function resolve(): array
    {
        $resolved = $open = 0;

        foreach (ResearchSignal::whereIn('status', [ResearchSignal::PENDING, ResearchSignal::OPEN])->get() as $s) {
            $bars = NseDailyBar::where('symbol', $s->symbol)->where('date', '>', $s->signal_date->toDateString())
                ->orderBy('date')->limit($s->rules['max_hold'] + 1)->get()->values();
            $this->walk($s, $bars);
            $s->save();
            $s->status === ResearchSignal::CLOSED || $s->status === ResearchSignal::NO_ENTRY ? $resolved++ : $open++;
        }

        return ['resolved' => $resolved, 'still_open' => $open];
    }

    public function walk(ResearchSignal $s, $bars): void
    {
        $r = $s->rules;

        if ($bars->isEmpty()) {
            return; // the entry session has not printed yet
        }

        $entryBar = $bars[0];

        if ($entryBar->open > $s->signal_close * (1 + $r['max_entry_gap'])) {
            $s->status = ResearchSignal::NO_ENTRY;
            $s->no_entry_reason = 'opened more than 2% above the signal close';

            return;
        }

        $entry = $entryBar->open * (1 + $r['slippage']);

        if ($entry <= $s->stop_price) {
            $s->status = ResearchSignal::NO_ENTRY;
            $s->no_entry_reason = 'opened at or below the stop';

            return;
        }

        $s->status = ResearchSignal::OPEN;
        $s->entry_date = $entryBar->date;
        $s->entry_price = $entry;
        $stop = $s->stop_price;
        $high = $entry;
        $atr = $s->atr;

        foreach ($bars->take($r['max_hold']) as $k => $bar) {
            $s->bars_held = $k + 1;

            if ($k > 0 && $bar->open <= $stop) {
                $this->close($s, $bar->date, $bar->open * (1 - $r['slippage']), 'stop_gap');

                return;
            }

            if ($bar->low <= $stop) {
                $this->close($s, $bar->date, $stop * (1 - $r['stop_slippage']), $stop > $s->stop_price ? 'trail_stop' : 'stop');

                return;
            }

            if ($k === $r['max_hold'] - 1) {
                $this->close($s, $bar->date, $bar->close * (1 - $r['slippage']), 'time');

                return;
            }

            // after the close: ATR moves on (Wilder), the trail ratchets up, never down
            $prevClose = $k === 0 ? $s->signal_close : $bars[$k - 1]->close;
            $tr = max($bar->high - $bar->low, abs($bar->high - $prevClose), abs($bar->low - $prevClose));
            $atr = ($atr * 13 + $tr) / 14;
            $high = max($high, $bar->close);
            $stop = max($stop, $high - $r['trail_atr'] * $atr);
        }

        $s->current_stop = $stop;
        $s->highest_close = $high;
    }

    protected function close(ResearchSignal $s, $date, float $price, string $reason): void
    {
        $qty = 10000 / $s->entry_price;
        $buy = 10000.0;
        $sell = $qty * $price;
        $brok = fn ($v) => min(20.0, $v * 0.001);
        $txn = 0.0000297 * ($buy + $sell);
        $sebi = 0.000001 * ($buy + $sell);
        $charges = $brok($buy) + $brok($sell) + 0.001 * ($buy + $sell) + $txn + $sebi + 0.00015 * $buy
            + 0.18 * ($brok($buy) + $brok($sell) + $txn + $sebi) + 15.93;
        $net = $sell - $buy - $charges;

        $s->status = ResearchSignal::CLOSED;
        $s->exit_date = $date;
        $s->exit_price = $price;
        $s->exit_reason = $reason;
        $s->net_pct = $net / $buy;
        $s->r_multiple = $net / (($s->entry_price - $s->stop_price) * $qty);
    }

    /** Live (forward) record, counted only for signals logged before their entry session. */
    public function liveStats(): array
    {
        $closed = ResearchSignal::where('status', ResearchSignal::CLOSED)->where('late', false)->get();
        $wins = $closed->where('net_pct', '>', 0);
        $losses = $closed->where('net_pct', '<=', 0);

        return [
            'logged' => ResearchSignal::count(),
            'late' => ResearchSignal::where('late', true)->count(),
            'open' => ResearchSignal::whereIn('status', [ResearchSignal::PENDING, ResearchSignal::OPEN])->count(),
            'no_entry' => ResearchSignal::where('status', ResearchSignal::NO_ENTRY)->count(),
            'closed' => $closed->count(),
            'win_rate' => $closed->count() ? $wins->count() / $closed->count() : null,
            'avg_r' => $closed->count() ? $closed->avg('r_multiple') : null,
            'profit_factor' => $losses->sum('net_pct') < 0 ? $wins->sum('net_pct') / -$losses->sum('net_pct') : null,
            'first_signal' => optional(ResearchSignal::min('signal_date'), fn ($d) => Carbon::parse($d)->toDateString()),
        ];
    }
}
