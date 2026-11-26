<?php

namespace App\Services\Swing;

use App\Models\SwingPaperTrade;
use App\Services\CandleStore;
use App\Services\IndicatorService;
use App\Services\TradeCostService;
use Illuminate\Support\Carbon;

/**
 * Writes plans down before their outcome exists, and fills the outcome in afterwards using
 * the same walk the backtest uses.
 */
class PaperTradeService
{
    /** A signal close that moved more than this was rescaled by a split or bonus. */
    protected const RESCALED = 0.01;

    protected const MARKET_OPEN = '09:15';

    /** Settings that decide how a trade is filled, sized, charged and closed. */
    protected const RULES = ['swing.execution', 'swing.exits', 'swing.costs'];

    public function __construct(
        protected CandleStore $candles,
        protected PortfolioLimitService $limits,
        protected IndicatorService $indicators,
    ) {
    }

    /**
     * @param  array<int, array{setup: SwingSetup, score: int, regime: ?string}>  $plans  in strategy order
     * @param  array<int, string>  $sessions  benchmark dates, any range reaching past the signal
     * @return array{logged: int, late: int, taken: int}
     */
    public function record(array $plans, array $sessions, string $kind = SwingPaperTrade::SIGNAL): array
    {
        $rules = [];

        foreach (self::RULES as $key) {
            $rules[$key] = config($key);
        }

        $onTime = array_filter($plans, fn ($p) => ! $this->isLate($p['setup']->signalDate, $sessions));
        $decisions = $kind === SwingPaperTrade::SIGNAL ? $this->portfolio($onTime) : [];
        $result = ['logged' => 0, 'late' => 0, 'taken' => 0];

        foreach ($plans as $plan) {
            $setup = $plan['setup'];
            $late = $this->isLate($setup->signalDate, $sessions);
            $decision = $decisions[$setup->symbol] ?? null;
            $chosen = $decision && $decision['strategy'] === $setup->strategy ? $decision : null;

            // Ignoring rather than updating is the point: the first version of a plan is
            // the only one that was written without knowing anything that came after.
            $inserted = SwingPaperTrade::query()->insertOrIgnore([
                'kind' => $kind,
                'symbol' => $setup->symbol,
                'strategy' => $setup->strategy,
                'direction' => $setup->direction,
                'signal_date' => $setup->signalDate,
                'logged_at' => now(),
                'late' => $late,
                'signal_price' => round($setup->signalPrice, 4),
                'entry_trigger' => $setup->entryTrigger,
                'max_entry' => $setup->maxEntry(),
                'stop_price' => $setup->stop,
                'stop_method' => $setup->stopMethod,
                'target1' => $setup->target1,
                'target2' => $setup->target2,
                'score' => $plan['score'],
                'regime_trend' => $plan['regime'],
                'rules' => json_encode($rules),
                'portfolio' => $chosen ? ($chosen['take'] ? 'TAKEN' : 'SKIPPED') : null,
                'portfolio_reason' => $chosen['reason'] ?? null,
                'planned_value' => $chosen['value'] ?? 0,
                'planned_risk' => $chosen['risk'] ?? 0,
                'status' => SwingPaperTrade::PENDING,
                'price_factor' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $result['logged'] += $inserted;
            $result['late'] += $inserted && $late ? 1 : 0;
            $result['taken'] += $inserted && $chosen && $chosen['take'] ? 1 : 0;
        }

        return $result;
    }

    /**
     * Fill in whatever the candles since have decided.
     *
     * @return array{opened: int, closed: int, no_entry: int}
     */
    public function resolve(): array
    {
        $result = ['opened' => 0, 'closed' => 0, 'no_entry' => 0];

        foreach (SwingPaperTrade::unresolved()->orderBy('id')->get()->groupBy('symbol') as $symbol => $trades) {
            $daily = $this->settledBars($this->candles->current((string) $symbol, '1d'));
            $index = array_flip(array_column($daily, 'date'));

            foreach ($trades as $trade) {
                $signalIndex = $index[$trade->signal_date->toDateString()] ?? null;

                // Without the signal's own bar there is nothing to count sessions from
                if ($signalIndex === null) {
                    continue;
                }

                $before = $trade->status;
                $this->underRulesOf($trade, fn (SwingBacktestService $walker) => $this->settle($trade, $walker, $daily, $signalIndex));

                if ($trade->status !== $before) {
                    match ($trade->status) {
                        SwingPaperTrade::OPEN => $result['opened']++,
                        SwingPaperTrade::CLOSED => $result['closed']++,
                        SwingPaperTrade::NO_ENTRY => $result['no_entry']++,
                    };
                }
            }
        }

        return $result;
    }

    protected function settle(SwingPaperTrade $trade, SwingBacktestService $walker, array $daily, int $signalIndex): void
    {
        $sessions = count($daily) - 1 - $signalIndex;

        if ($sessions < 1) {
            return;
        }

        $factor = $daily[$signalIndex]['close'] / $trade->signal_price;
        $factor = abs($factor - 1) > self::RESCALED ? $factor : 1.0;
        $setup = $trade->toSetup($factor);
        $result = $walker->walk($setup, $daily, $signalIndex);

        if ($result === null) {
            $reason = $walker->enter($setup, $daily, $signalIndex);

            if ($reason !== 'not_triggered' || $sessions >= config('swing.execution.entry_trigger_valid_days')) {
                $trade->update(['status' => SwingPaperTrade::NO_ENTRY, 'exit_reason' => $reason, 'price_factor' => $factor]);
            }

            return;
        }

        $entered = [
            'price_factor' => $factor,
            'entry_date' => $result->entryDate,
            'entry_price' => round($result->entryPrice, 4),
            'quantity' => $result->quantity,
            'bars_held' => $result->barsHeld,
        ];

        // The walk closes whatever is left on the last bar it is given. Short of the holding
        // limit, that bar is only today.
        if ($result->exitReason() === 'max_holding_days' && $result->barsHeld < config('swing.exits.max_holding_days')) {
            $trade->update($entered + ['status' => SwingPaperTrade::OPEN]);

            return;
        }

        $trade->update($entered + [
            'status' => SwingPaperTrade::CLOSED,
            'exit_date' => $result->exitDate(),
            'exit_price' => $result->exitPrice(),
            'exit_reason' => $result->exitReason(),
            'gross_pnl' => round($result->grossPnl, 2),
            'costs' => round($result->costs, 2),
            'net_pnl' => round($result->netPnl, 2),
            'r_multiple' => round($result->rMultiple, 4),
        ]);
    }

    /**
     * One plan per stock, best first in the dashboard's own order, against the limits.
     *
     * Watch-only plans are logged but never take a slot (swing.trust), so they are measured
     * without ever reaching the account or Telegram.
     *
     * @return array<string, array>
     */
    protected function portfolio(array $plans): array
    {
        $trading = collect($plans)->reject(fn ($p) => SwingTrust::isWatchOnly($p['setup']->strategy));

        $best = $trading->groupBy(fn ($p) => $p['setup']->symbol)->map(function ($rows) {
            $top = SwingTrust::sort($rows, fn ($p) => $p['setup']->strategy)->first();

            return [
                'symbol' => $top['setup']->symbol,
                'strategy' => $top['setup']->strategy,
                'entry' => $top['setup']->referencePrice(),
                'stop' => $top['setup']->stop,
                'rank' => SwingTrust::rank($rows->map(fn ($p) => $p['setup']->strategy)),
            ];
        })->sortByDesc('rank')->values();

        $decisions = $this->limits->decide($best);

        return $best->mapWithKeys(fn ($p) => [$p['symbol'] => $decisions[$p['symbol']] + ['strategy' => $p['strategy']]])->all();
    }

    /**
     * Logged after the entry session had opened, or after one already had.
     *
     * Today counts as open from 9:15 on any weekday. On a holiday that is wrong, harmlessly:
     * the evening before has already logged the same plans on time.
     */
    protected function isLate(string $signalDate, array $sessions): bool
    {
        $now = now('Asia/Kolkata');
        $todayHasOpened = $now->toDateString() > $signalDate
            && $now->isWeekday()
            && $now->format('H:i') >= self::MARKET_OPEN;

        $sinceThen = array_filter($sessions, fn ($date) => $date > $signalDate && $date < $now->toDateString());

        return $todayHasOpened || $sinceThen !== [];
    }

    /** Completed bars only. The exchange's own close is not final for a while after 15:30. */
    protected function settledBars(array $daily): array
    {
        $now = now('Asia/Kolkata');
        $settled = $now->copy()->setTimeFromTimeString(config('screener.market_close'))->addMinutes(CandleStore::SETTLE_MINUTES);

        if ($daily && end($daily)['date'] === $now->toDateString() && $now->lt($settled)) {
            array_pop($daily);
        }

        return $daily;
    }

    /** Run under the settings the trade was logged with, whatever config says today. */
    protected function underRulesOf(SwingPaperTrade $trade, callable $callback): void
    {
        $current = [];

        foreach ($trade->rules as $key => $value) {
            $current[$key] = config($key);
            config([$key => $value]);
        }

        try {
            // Built here, not injected: the walker reads its charges when it is constructed
            $callback(new SwingBacktestService(new TradeCostService, $this->indicators));
        } finally {
            config($current);
        }
    }
}
