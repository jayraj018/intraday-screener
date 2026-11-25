<?php

namespace App\Services;

use App\Models\IntradayAlert;
use App\Models\IntradayPick;
use Illuminate\Support\Carbon;

/**
 * Watches the day's practice stocks during the session: one message when a stock can be
 * bought at the price it is trading at now, and one when that trade reaches its stop, its
 * target or the square-off time. Longs only, and no money.
 *
 * The random stocks go through the same entry and exit rules without a message, so that the
 * picks can be compared with no picking at all.
 *
 * It sees prices once per run, so it can be minutes behind.
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
        protected IntradayPickService $picks,
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
        $waiting = $this->picks->today()->whereNotIn('symbol', $already);

        foreach ($waiting as $pick) {
            $alert = $this->alertFor($pick, $today);

            if ($alert && $this->deliver($alert) && $alert->kind === IntradayAlert::SIGNAL) {
                $sent[] = $pick->symbol;
            }
        }

        return ['watched' => $waiting->where('kind', IntradayAlert::SIGNAL)->count(), 'sent' => $sent, 'reason' => null];
    }

    /**
     * Close whatever has reached its stop, its target or the square-off time, and say so.
     *
     * @return array<int, string> symbols an exit message was sent for
     */
    public function follow(): array
    {
        // Five days is as far back as the 5-minute prices go
        $since = now('Asia/Kolkata')->subDays(5)->toDateString();

        foreach (IntradayAlert::where('status', 'OPEN')->where('alert_date', '>=', $since)->get() as $alert) {
            $this->resolve($alert);
        }

        $sent = [];

        // Kept apart from resolving, so an exit that could not be delivered is sent next time
        $closed = IntradayAlert::where('kind', IntradayAlert::SIGNAL)->where('status', '!=', 'OPEN')->where('alert_date', '>=', $since);

        foreach ($closed->get()->reject->exit_alerted as $alert) {
            if ($this->telegram->send($this->exitMessage($alert))) {
                $alert->update(['exit_alerted' => true]);
                $sent[] = $alert->symbol;
            }
        }

        return $sent;
    }

    protected function resolve(IntradayAlert $alert): void
    {
        $squareOff = Carbon::parse($alert->day() . ' ' . config('screener.live.square_off_at'), 'Asia/Kolkata');
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

    protected function alertFor(IntradayPick $pick, string $today): ?IntradayAlert
    {
        $candles = $this->data->getIntradayCandles($pick->symbol, '5d') ?? [];
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
            'kind' => $pick->kind,
            'symbol' => $pick->symbol,
            'price_time' => $decision['as_of'],
            'entry' => $decision['entry'],
            'max_entry' => $this->maxEntry($decision['stop_loss'], $decision['target']),
            'stop_loss' => $decision['stop_loss'],
            'target' => $decision['target'],
            'quantity' => $quantity,
            'reason' => $pick->kind === IntradayAlert::SIGNAL ? $pick->reason() : 'random stock',
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

        if ($alert->kind === IntradayAlert::RANDOM || $this->telegram->send($this->message($alert))) {
            return true;
        }

        IntradayAlert::where('alert_date', $alert->alert_date)->where('symbol', $alert->symbol)->delete();

        return false;
    }

    protected function message(IntradayAlert $alert): string
    {
        $money = fn (float $v) => '₹' . number_format($v, 2);

        return implode("\n", [
            "🟢 PRACTICE BUY {$alert->symbol} x{$alert->quantity}",
            "Price {$money($alert->entry)} at {$alert->price_time} — not above {$money($alert->max_entry)}",
            "Stop {$money($alert->stop_loss)} · Target {$money($alert->target)}",
            'Already above ' . $money($alert->max_entry) . '? Do not buy. Below the stop? Do not buy.',
            'Sell by ' . config('screener.live.square_off_at') . ' whatever happens.',
            "Why: {$alert->reason}; now above VWAP and 20 EMA with volume.",
            '⚠️ PRACTICE — no real money. These rules lost money when tested on past prices.',
        ]);
    }

    protected function exitMessage(IntradayAlert $alert): string
    {
        $headline = match ($alert->status) {
            'TARGET' => "🟢 PRACTICE EXIT {$alert->symbol} — target reached",
            'STOP' => "🔴 PRACTICE EXIT {$alert->symbol} — stop reached",
            default => "⏰ PRACTICE EXIT {$alert->symbol} — {$alert->exit_time}, time to close",
        };

        return implode("\n", [
            $headline,
            sprintf('Around ₹%s at %s. Alert price was ₹%s.', number_format($alert->exit_price, 2), $alert->exit_time, number_format($alert->entry, 2)),
            sprintf('Practice result: %s₹%s after charges (%+.2fR)', $alert->net_pnl < 0 ? '−' : '+', number_format(abs($alert->net_pnl), 2), $alert->r_multiple),
            'This practice trade is finished.',
        ]);
    }
}
