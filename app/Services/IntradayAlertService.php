<?php

namespace App\Services;

use App\Models\IntradayAlert;
use App\Models\ScreenerResult;
use App\Models\StrategyStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Watches the morning scan's BUY stocks during the session: one message when a stock can be
 * bought at the price it is trading at now, and one when that trade reaches its stop, its
 * target or the square-off time. Longs only.
 *
 * It sees prices once per run, so it can be minutes behind. A stop order with the broker is
 * the protection; the exit message is the record of it.
 */
class IntradayAlertService
{
    /** A candle older than this is not a price anybody can still get. */
    protected const MAX_PRICE_AGE_MINUTES = 15;

    public function __construct(
        protected ScreenerService $screener,
        protected StockDataService $data,
        protected TradeCostService $costs,
        protected TelegramService $telegram,
    ) {
    }

    /** Between the first usable VWAP and the last moment a new trade may be opened. */
    public function isOpen(): bool
    {
        $now = now('Asia/Kolkata');

        return $now->isWeekday()
            && $now->format('H:i') >= config('screener.alerts.start')
            && $now->format('H:i') < config('screener.live.no_new_entry_after');
    }

    /**
     * @return array{watched: int, sent: array<int, string>, reason: ?string}
     */
    public function watch(): array
    {
        $today = now('Asia/Kolkata')->toDateString();
        $sent = [];

        if (! config('screener.alerts.enabled')) {
            return ['watched' => 0, 'sent' => [], 'reason' => 'Intraday alerts are off (INTRADAY_ALERTS).'];
        }

        if (! $this->isOpen()) {
            return ['watched' => 0, 'sent' => [], 'reason' => 'Outside the alert hours.'];
        }

        if (! $this->telegram->isConfigured()) {
            return ['watched' => 0, 'sent' => [], 'reason' => 'Telegram is not set up.'];
        }

        $already = IntradayAlert::where('alert_date', $today)->pluck('symbol');
        $room = config('screener.alerts.max_per_day') - $already->count();

        if ($room <= 0) {
            return ['watched' => 0, 'sent' => [], 'reason' => 'Today\'s alert limit has been reached.'];
        }

        $candidates = $this->candidates($today)->whereNotIn('symbol', $already)->take(config('screener.alerts.max_watched'));

        foreach ($candidates as $candidate) {
            if (count($sent) >= $room) {
                break;
            }

            $alert = $this->alertFor($candidate, $today);

            if ($alert && $this->deliver($alert)) {
                $sent[] = $candidate['symbol'];
            }
        }

        return ['watched' => $candidates->count(), 'sent' => $sent, 'reason' => $candidates->isEmpty() ? 'No stock qualifies today.' : null];
    }

    /**
     * Close whatever has reached its stop, its target or the square-off time, and say so.
     *
     * @return array<int, string> symbols an exit message was sent for
     */
    public function follow(): array
    {
        // Five days is as far back as the 5-minute prices go
        $open = IntradayAlert::where('status', 'OPEN')->where('alert_date', '>=', now('Asia/Kolkata')->subDays(5)->toDateString());

        foreach ($open->get() as $alert) {
            $this->resolve($alert);
        }

        $sent = [];

        // Kept apart from resolving, so an exit that could not be delivered is sent next time
        foreach (IntradayAlert::where('status', '!=', 'OPEN')->get()->reject->exit_alerted as $alert) {
            if ($this->telegram->send($this->exitMessage($alert))) {
                $alert->update(['exit_alerted' => true]);
                $sent[] = $alert->symbol;
            }
        }

        return $sent;
    }

    protected function resolve(IntradayAlert $alert): void
    {
        $day = substr($alert->alert_date, 0, 10);
        $squareOff = Carbon::parse($day . ' ' . config('screener.live.square_off_at'), 'Asia/Kolkata');
        $last = null;

        foreach ($this->data->getIntradayCandles($alert->symbol, '5d') ?? [] as $candle) {
            if ($candle['timestamp'] < $alert->candle_at || $candle['timestamp'] >= $squareOff->timestamp) {
                continue;
            }

            $last = $candle;
            $own = $candle['timestamp'] === $alert->candle_at;

            // A candle that reached both cannot say which came first, so it counts as the stop
            if ($candle['low'] <= $alert->stop_loss && (! $own || $candle['low'] < $alert->seen_low)) {
                $this->close($alert, 'STOP', $own ? $alert->stop_loss : min($alert->stop_loss, $candle['open']), $candle['timestamp']);

                return;
            }

            if ($candle['high'] >= $alert->target && (! $own || $candle['high'] > $alert->seen_high)) {
                $this->close($alert, 'TARGET', $own ? $alert->target : max($alert->target, $candle['open']), $candle['timestamp']);

                return;
            }
        }

        if ($last && now()->gte($squareOff)) {
            $this->close($alert, 'TIME', $last['close'], $squareOff->timestamp);
        }
    }

    protected function close(IntradayAlert $alert, string $status, float $price, int $at): void
    {
        $entry = $this->costs->slip($alert->entry, buying: true);
        $exit = $status === 'TARGET' ? $price : $this->costs->slip($price, buying: false);
        $net = ($exit - $entry) * $alert->quantity - $this->costs->roundTripCosts($entry, $exit, $alert->quantity, isBuyFirst: true);

        $alert->update([
            'status' => $status,
            'exit_time' => Carbon::createFromTimestamp($at, 'Asia/Kolkata')->format('H:i'),
            'exit_price' => round($exit, 2),
            'net_pnl' => round($net, 2),
            'r_multiple' => round($net / (($alert->entry - $alert->stop_loss) * $alert->quantity), 4),
        ]);
    }

    /**
     * Stocks enough strategies call a BUY and none calls a SELL, strongest first.
     *
     * Once a backtest has been recorded only the strategies it passed get a vote, as on the
     * dashboard. Before that nothing has been tested, so all of them do and the message says
     * so. ORB is left out: its entry was taken at a price that has already gone.
     *
     * @return Collection<int, array{symbol: string, strategies: array<int, string>, tested: bool, surge: bool}>
     */
    public function candidates(string $today): Collection
    {
        $stats = StrategyStat::all();
        $tested = $stats->isNotEmpty();
        $qualified = $stats->filter->qualifies()->pluck('strategy');
        $results = ScreenerResult::whereDate('scan_date', $today)->get();
        $shorted = $results->reject(fn ($r) => str_contains($r->signal, 'BUY'))->pluck('symbol')->unique();

        return $results
            ->filter(fn ($r) => str_contains($r->signal, 'BUY') && $r->strategy !== 'ORB + VWAP Breakout')
            ->filter(fn ($r) => ! $tested || $qualified->contains($r->strategy))
            ->whereNotIn('symbol', $shorted)
            ->groupBy('symbol')
            ->map(fn ($rows, $symbol) => [
                'symbol' => (string) $symbol,
                'strategies' => $rows->pluck('strategy')->unique()->values()->all(),
                'tested' => $tested,
                'surge' => $rows->contains('volume_surge', true),
            ])
            ->filter(fn ($c) => count($c['strategies']) >= config('screener.min_strategies_agree'))
            ->sortByDesc(fn ($c) => [count($c['strategies']), $c['surge']])
            ->values();
    }

    protected function alertFor(array $candidate, string $today): ?IntradayAlert
    {
        $candles = $this->data->getIntradayCandles($candidate['symbol'], '5d') ?? [];
        $latest = end($candles);

        if (! $latest || Carbon::createFromTimestamp($latest['timestamp'])->diffInMinutes(now(), true) > self::MAX_PRICE_AGE_MINUTES) {
            return null;
        }

        $decision = $this->screener->liveTradeDecision($candles);

        if (($decision['action'] ?? null) !== 'BUY') {
            return null;
        }

        $quantity = $this->costs->quantity($decision['entry'], $decision['risk']);

        if ($quantity < 1) {
            return null;
        }

        return new IntradayAlert([
            'alert_date' => $today,
            'symbol' => $candidate['symbol'],
            'price_time' => $decision['as_of'],
            'entry' => $decision['entry'],
            'max_entry' => $this->maxEntry($decision['stop_loss'], $decision['target']),
            'stop_loss' => $decision['stop_loss'],
            'target' => $decision['target'],
            'quantity' => $quantity,
            'strategies' => $candidate['strategies'],
            'tested' => $candidate['tested'],
            'candle_at' => $latest['timestamp'],
            'seen_low' => $latest['low'],
            'seen_high' => $latest['high'],
        ]);
    }

    /** The price at which the target still pays the smallest reward worth the risk. */
    protected function maxEntry(float $stop, float $target): float
    {
        $ratio = config('screener.alerts.min_risk_reward');

        return floor(round(($target + $ratio * $stop) / (1 + $ratio) * 100, 4)) / 100;
    }

    /**
     * Claimed before it is sent, so two overlapping runs cannot both send it, and released
     * if it could not be delivered, so the next run tries again at that moment's price.
     */
    protected function deliver(IntradayAlert $alert): bool
    {
        $claimed = IntradayAlert::query()->insertOrIgnore([
            ...$alert->getAttributes(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        if (! $claimed) {
            return false;
        }

        if ($this->telegram->send($this->message($alert))) {
            return true;
        }

        IntradayAlert::where('alert_date', $alert->alert_date)->where('symbol', $alert->symbol)->delete();

        return false;
    }

    protected function message(IntradayAlert $alert): string
    {
        $money = fn (float $v) => '₹' . number_format($v, 2);

        return implode("\n", [
            "🟢 INTRADAY BUY {$alert->symbol} x{$alert->quantity}",
            "Price {$money($alert->entry)} at {$alert->price_time} — not above {$money($alert->max_entry)}",
            "Stop {$money($alert->stop_loss)} · Target {$money($alert->target)}",
            'Already above ' . $money($alert->max_entry) . '? Do not buy. Below the stop? Do not buy.',
            'Sell by ' . config('screener.live.square_off_at') . ' whatever happens.',
            'Why: ' . implode(', ', $alert->strategies) . '; above VWAP and 20 EMA with volume.',
            '⚠️ LOST MONEY IN TESTING — ' . config('screener.alerts.test_result') . '. Paper trade only.',
        ]);
    }

    protected function exitMessage(IntradayAlert $alert): string
    {
        $headline = match ($alert->status) {
            'TARGET' => "🟢 EXIT {$alert->symbol} — target reached",
            'STOP' => "🔴 EXIT {$alert->symbol} — stop reached",
            default => "⏰ EXIT {$alert->symbol} — {$alert->exit_time}, time to close",
        };

        return implode("\n", [
            $headline,
            sprintf('Around ₹%s at %s. Alert price was ₹%s.', number_format($alert->exit_price, 2), $alert->exit_time, number_format($alert->entry, 2)),
            sprintf('Result if taken: %s₹%s after charges (%+.2fR)', $alert->net_pnl < 0 ? '−' : '+', number_format(abs($alert->net_pnl), 2), $alert->r_multiple),
            'Still holding it? The plan is finished.',
        ]);
    }
}
