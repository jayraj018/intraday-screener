<?php

namespace App\Services;

use App\Models\IntradayAlert;
use App\Models\IntradayPick;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the day's practice stocks before the open: strong stocks after a small dip, and as
 * many chosen by a hash for comparison. News only ever removes a stock, never adds one.
 *
 * The rule is the one tested on five years of daily prices, where it did no better than any
 * liquid stock. It is here to be measured, not followed.
 */
class IntradayPickService
{
    /** Sessions the rule looks back over, and the calendar days sure to contain them. */
    protected const SESSIONS = 61;
    protected const CALENDAR_DAYS = 130;

    public function __construct(
        protected ScreenerService $screener,
        protected CandleStore $store,
        protected NseService $nse,
        protected TelegramService $telegram,
    ) {
    }

    /** @return Collection<int, IntradayPick> */
    public function today(): Collection
    {
        return IntradayPick::where('pick_date', now('Asia/Kolkata')->toDateString())->orderBy('position')->get();
    }

    /**
     * Today's picks, chosen once: a second run returns the same ones, whatever has happened since.
     *
     * @return array{picks: Collection<int, IntradayPick>, news: ?bool, skipped: array<int, string>}
     *         news is null when nothing was chosen by this call
     */
    public function pick(?callable $progress = null): array
    {
        $existing = $this->today();

        if ($existing->isNotEmpty()) {
            return ['picks' => $existing, 'news' => null, 'skipped' => []];
        }

        $today = now('Asia/Kolkata')->toDateString();
        [$stocks, $session] = $this->liquidStocks($today, $progress);

        if (count($stocks) < config('screener.alerts.min_stocks')) {
            throw new \RuntimeException('Only ' . count($stocks) . ' stocks have fresh prices, so no stocks were picked.');
        }

        $risky = $this->riskyStocks($session);
        $count = config('screener.alerts.picks');
        $ranked = $this->ranked($stocks);

        $picks = $ranked->reject(fn ($s) => isset($risky['symbols'][$s['symbol']]))->take($count)->values();
        $random = $stocks->reject(fn ($s) => isset($risky['symbols'][$s['symbol']]) || $picks->contains('symbol', $s['symbol']))
            ->sortBy(fn ($s) => md5("{$s['symbol']}|{$today}|intraday"))->take($count)->values();

        DB::transaction(function () use ($picks, $random, $today) {
            foreach ([IntradayAlert::SIGNAL => $picks, IntradayAlert::RANDOM => $random] as $kind => $group) {
                foreach ($group as $i => $stock) {
                    IntradayPick::create([
                        'pick_date' => $today, 'kind' => $kind, 'position' => $i + 1, 'symbol' => $stock['symbol'],
                        'close' => round($stock['close'], 2), 'return_60' => round($stock['return_60'], 2), 'return_3' => round($stock['return_3'], 2),
                    ]);
                }
            }
        });

        return [
            'picks' => $this->today(),
            'news' => $risky['read'],
            'skipped' => $ranked->takeUntil(fn ($s) => $picks->isNotEmpty() && $s['symbol'] === $picks->last()['symbol'])
                ->filter(fn ($s) => isset($risky['symbols'][$s['symbol']]))
                ->map(fn ($s) => "{$s['symbol']} ({$risky['symbols'][$s['symbol']]})")->values()->all(),
        ];
    }

    /** Sends the watchlist once. True when it has been delivered, now or earlier. */
    public function announce(array $result): bool
    {
        $picks = $result['picks'];

        if ($picks->isEmpty() || $picks->first()->announced) {
            return $picks->isNotEmpty();
        }

        if (! $this->telegram->send($this->message($result))) {
            return false;
        }

        IntradayPick::whereIn('id', $picks->pluck('id'))->update(['announced' => true]);

        return true;
    }

    /**
     * Every stock traded heavily enough, with what the rule needs to know about it, from
     * finished sessions only.
     *
     * @return array{0: Collection<int, array>, 1: ?string} the stocks and the last session's date
     */
    protected function liquidStocks(string $today, ?callable $progress): array
    {
        $from = Carbon::parse($today)->subDays(self::CALENDAR_DAYS)->toDateString();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $stocks = [];

        foreach ($symbols = $this->screener->watchlist() as $symbol) {
            $progress && $progress(count($symbols));

            if (! $this->store->isCurrent($symbol)) {
                $this->store->freshen($symbol);

                if (! $this->store->isCurrent($symbol)) {
                    continue;
                }
            }

            if ($stock = $this->measure($symbol, $this->store->get($symbol, '1d', $from, $yesterday))) {
                $stocks[] = $stock;
            }
        }

        // A suspended stock's last bar is an old one
        $session = collect($stocks)->max('date');

        return [collect($stocks)->where('date', $session)->values(), $session];
    }

    protected function measure(string $symbol, array $candles): ?array
    {
        $last = count($candles) - 1;

        if ($last + 1 < self::SESSIONS || $candles[$last - 60]['close'] <= 0) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $average = fn (array $values) => array_sum($values) / count($values);

        // The day itself is left out, as in the test
        if ($average(array_column(array_slice($candles, -21, 20), 'volume')) < config('screener.min_avg_volume')) {
            return null;
        }

        $sma50 = $average(array_slice($closes, -50));

        return [
            'symbol' => $symbol,
            'date' => $candles[$last]['date'],
            'close' => $closes[$last],
            'return_60' => ($closes[$last] / $closes[$last - 60] - 1) * 100,
            'return_3' => ($closes[$last] / $closes[$last - 3] - 1) * 100,
            'uptrend' => $closes[$last] > $sma50 && $average(array_slice($closes, -20)) > $sma50,
        ];
    }

    /** Stocks that fit the rule, strongest first. */
    protected function ranked(Collection $stocks): Collection
    {
        $rule = config('screener.alerts.rule');

        return $stocks->sortByDesc('return_60')
            ->take((int) ($stocks->count() * $rule['top_share']))
            ->filter(fn ($s) => $s['uptrend'] && $s['return_3'] >= $rule['dip_min'] && $s['return_3'] <= $rule['dip_max'])
            ->values();
    }

    /**
     * Companies with a board meeting today, or that have told the exchange something since the
     * last session that makes today unpredictable.
     *
     * @return array{symbols: array<string, string>, read: bool} symbol => why; read is false when the news could not be checked
     */
    protected function riskyStocks(?string $session): array
    {
        $now = now('Asia/Kolkata');
        $symbols = [];

        // Asked for a few days around today, because an empty answer for one day proves nothing
        $meetings = $this->nse->boardMeetings($now->copy()->subDays(3), $now->copy()->addDays(3));

        foreach ($meetings as $meeting) {
            if ($meeting['date'] === $now->toDateString()) {
                $symbols[$meeting['symbol']] = $meeting['purpose'];
            }
        }

        $announcements = $this->nse->announcements($session ? Carbon::parse($session, 'Asia/Kolkata') : $now->copy()->subDay(), $now);

        foreach ($announcements ?? [] as $row) {
            if ($this->isRisky($row)) {
                $symbols[$row['symbol']] ??= $row['category'];
            }
        }

        return ['symbols' => $symbols, 'read' => $meetings !== [] && $announcements !== null];
    }

    protected function isRisky(array $announcement): bool
    {
        foreach (config('screener.alerts.risky_categories') as $part) {
            if (str_contains(strtolower($announcement['category']), $part)) {
                return true;
            }
        }

        $words = implode('|', array_map(fn ($w) => preg_quote($w, '/'), config('screener.alerts.risky_words')));

        return (bool) preg_match("/\\b({$words})\\b/i", $announcement['text']);
    }

    protected function message(array $result): string
    {
        $picks = $result['picks']->where('kind', IntradayAlert::SIGNAL);

        return implode("\n", array_filter([
            "📋 PRACTICE — today's watchlist: " . ($picks->isEmpty() ? 'no stock fits the rule' : $picks->count() . ' stock(s)'),
            ...$picks->map(fn ($p) => "{$p->position}. {$p->symbol} (₹" . number_format($p->close, 2) . ') — ' . $p->reason())->all(),
            $result['skipped'] ? 'Left out because of news: ' . implode(', ', $result['skipped']) : null,
            $result['news'] === false ? '⚠️ The news could not be checked today.' : null,
            $result['news'] === true ? 'News checked: exchange announcements since the last session. Newspapers are not read.' : null,
            $picks->isEmpty() ? null : 'Do not buy from this list. A message follows only if a stock sets up after ' . config('screener.alerts.start') . '.',
            '⚠️ No real money. This rule did no better than any stock over 5 years of testing.',
        ]));
    }
}
