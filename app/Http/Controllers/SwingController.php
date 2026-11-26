<?php

namespace App\Http\Controllers;

use App\Models\SwingSignal;
use App\Models\SwingPaperTrade;
use App\Services\CandleStore;
use App\Services\ScreenerService;
use App\Services\StockDataService;
use App\Services\Swing\MarketRegimeService;
use App\Services\Swing\PaperTradeStatsService;
use App\Services\Swing\PlanValidityService;
use App\Services\Swing\PortfolioLimitService;
use App\Services\Swing\SignalScoringService;
use App\Services\Swing\StrategyAgreementService;
use App\Services\Swing\SwingScannerService;
use App\Services\Swing\SwingSignal as Signal;
use App\Services\Swing\SwingTrust;
use Illuminate\Http\Request;

class SwingController extends Controller
{
    public function __construct(
        protected StrategyAgreementService $agreement,
        protected PlanValidityService $validity,
        protected PortfolioLimitService $limits,
    ) {
    }

    /**
     * Every swing strategy's verdict on one stock, right now.
     *
     * The answer is deliberately never a bare "BUY". What the rules can honestly report is
     * whether a setup has triggered, how much independent evidence supports it, and what
     * the plan would be — so the verdict is SETUP / WAIT / TOO LATE / NO SETUP, and the
     * entry it quotes is the next session's, not the price on screen.
     */
    public function analyze(
        Request $request,
        CandleStore $candles,
        StockDataService $data,
        MarketRegimeService $regimes,
        SwingScannerService $scanner,
        SignalScoringService $scoring,
        ScreenerService $screener,
    ) {
        $symbol = preg_replace('/[^A-Z0-9&-]/', '', strtoupper((string) $request->query('symbol')));

        if ($symbol === '') {
            return response()->json(['error' => 'Please enter a stock symbol.'], 400);
        }

        $daily = $candles->freshen($symbol, '1d');

        if (count($daily) < 260) {
            return response()->json([
                'error' => count($daily) === 0
                    ? "Couldn't find '{$symbol}' on NSE. Check the spelling — some tickers change after a merger or demerger."
                    : "'{$symbol}' has only " . count($daily) . " days of history. Swing strategies need about 260 for a 200-day trend.",
            ], count($daily) === 0 ? 404 : 422);
        }

        $context = app(\App\Services\Swing\SwingContextBuilder::class)->build(
            $symbol,
            $daily,
            $candles->freshen($symbol, '1wk'),
            $benchmark = $candles->freshen(config('swing.regime.benchmark'), '1d'),
            $regimes->detect($screener->completedDailyCandles($benchmark)),
        );

        // Most trusted first, watch-only last, so the list reads in the order it is trusted
        $strategies = SwingTrust::sort(collect($scanner->strategies()), fn ($s) => $s->name())->all();
        $signals = array_map(fn ($strategy) => $strategy->evaluate($context), $strategies);
        $assessment = $this->agreement->assess($signals);
        $validity = $this->validity->forSignal($context->date(), array_column($benchmark, 'date'));
        $held = SwingPaperTrade::held()->get();

        // The live price, purely so the gap to the signal close is visible. The plan is
        // built on the last completed close; this is what the stock is doing since.
        $intraday = $data->getIntradayCandles($symbol);
        $livePrice = $intraday ? round(end($intraday)['close'], 2) : null;

        return response()->json([
            'label' => 'NOT TESTED',
            'caveats' => [
                'No swing strategy here has been validated out of sample.',
                'A setup is what the rules found, not a prediction that it will work.',
                'The plan is built on the last completed daily close; the entry is the next session.',
            ],
            'symbol' => $symbol,
            'as_of' => $context->date(),
            'signal_close' => round($context->close(), 2),
            'current_price' => $livePrice,
            'verdict' => $this->verdict($signals, $assessment),

            // The stock's own condition, before any plan. Six of the seven scoring groups
            // read the stock rather than the setup, so this is identical whichever
            // strategy is asking — showing it once avoids seven identical numbers reading
            // as seven separate findings.
            'condition_score' => $scoring->score($context)['score'],
            'condition_groups' => $scoring->score($context)['groups'],
            'regime' => $context->regime,
            'relative_strength' => $context->relativeStrength,
            'weekly_trend' => $context->weeklyTrend,
            'agreement' => $assessment + ['summary' => $this->agreement->describe($assessment)],
            'strategies' => array_map(
                fn (Signal $s) => $this->strategyPayload($s, $context, $scoring, $validity, $held),
                $signals
            ),
        ]);
    }

    /**
     * @param  array<int, Signal>  $signals
     */
    protected function verdict(array $signals, array $assessment): array
    {
        $states = array_map(fn (Signal $s) => $s->state, $signals);

        if ($assessment['qualifies']) {
            return [
                'code' => 'SETUP',
                'headline' => sprintf('%d independent families agree', $assessment['families']),
                'detail' => 'Every condition is met. The entry is a resting order for the next session.',
            ];
        }

        if ($assessment['strategies'] > 0) {
            return [
                'code' => 'SETUP_THIN',
                'headline' => 'A setup triggered, but the evidence is thin',
                'detail' => $this->agreement->describe($assessment)
                    . ' At least ' . config('swing.agreement.min_families_agree') . ' independent families are wanted.',
            ];
        }

        $watchOnly = array_filter($signals, fn (Signal $s) => $s->isActionable() && SwingTrust::isWatchOnly($s->strategy));

        if ($watchOnly) {
            return [
                'code' => 'WATCH_ONLY',
                'headline' => 'Only a watch-only strategy has a setup',
                'detail' => implode(', ', array_map(fn (Signal $s) => $s->strategy, $watchOnly))
                    . ' found a setup, but did no better than random stocks in testing. It is logged on paper only.',
            ];
        }

        if (in_array(Signal::EXTENDED, $states, true)) {
            return [
                'code' => 'TOO_LATE',
                'headline' => 'The move already happened',
                'detail' => 'A setup was valid, but price has run past it. Entering now takes a different risk to the plan.',
            ];
        }

        if (in_array(Signal::WATCHLIST, $states, true)) {
            return [
                'code' => 'WAIT',
                'headline' => 'Close, but nothing has triggered',
                'detail' => 'Conditions are building. See what each strategy is waiting for below.',
            ];
        }

        return [
            'code' => 'NO_SETUP',
            'headline' => 'No swing setup',
            'detail' => 'None of the ' . count($signals) . ' strategies sees anything here today.',
        ];
    }

    protected function strategyPayload(Signal $signal, $context, SignalScoringService $scoring, array $validity, $held): array
    {
        $setup = $signal->setup;
        $limit = $setup === null ? null : $this->limits->decide([
            ['symbol' => $setup->symbol, 'entry' => $setup->referencePrice(), 'stop' => $setup->stop],
        ], $held)[$setup->symbol];

        return [
            'strategy' => $signal->strategy,
            'family' => config("swing.agreement.families.{$signal->strategy}", $signal->strategy),
            'watch_only' => SwingTrust::isWatchOnly($signal->strategy),
            'state' => $signal->state,
            'score' => $scoring->score($context, $setup)['score'],
            'watch_for' => $signal->watchFor,
            'checks' => $signal->checks,
            'plan' => $setup === null ? null : [
                'direction' => $setup->direction,
                'entry' => $setup->entryTrigger ?? $setup->signalPrice,
                'entry_is_trigger' => $setup->entryTrigger !== null,
                'stop' => $setup->stop,
                'stop_method' => $setup->stopMethod,
                'target1' => $setup->target1,
                'target1_r' => $setup->rMultiple($setup->target1),
                'target2' => $setup->target2,
                'target2_r' => $setup->target2 === null ? null : $setup->rMultiple($setup->target2),
                'risk_per_share' => round($setup->plannedRisk(), 2),
                'risk_percent' => round($setup->plannedRisk() / $setup->referencePrice() * 100, 2),
                'max_entry' => $setup->maxEntry(),
                'risk_percent_at_max_entry' => round(abs($setup->maxEntry() - $setup->stop) / $setup->maxEntry() * 100, 2),
                'valid_for' => $validity,
                'portfolio' => $limit,
            ],
        ];
    }

    public function index()
    {
        $scanDate = SwingSignal::max('scan_date');
        // By symbol, not score: the score picked no better than chance (swing.trust)
        $signals = $scanDate ? SwingSignal::forDate($scanDate)->orderBy('symbol')->get() : collect();
        $grouped = $this->groupBySymbol($signals->where('state', 'READY'));
        $ready = $grouped->reject->watchOnly->values();
        $decisions = $this->decisions($ready);
        $signalDate = $signals->max('signal_date');
        $sessions = $signalDate ? $this->validity->sessionsSince($ready->min('best.signal_date')?->toDateString() ?? $signalDate->toDateString()) : [];

        return view('swing.index', [
            'scanDate' => $scanDate,
            'ready' => $ready,
            'decisions' => $decisions,
            'validity' => $ready->mapWithKeys(fn ($s) => [$s->symbol => $s->best->signal_date
                ? $this->validity->forSignal($s->best->signal_date->toDateString(), $sessions)
                : null]),
            'limits' => config('swing.portfolio'),
            'watchOnly' => $grouped->filter->watchOnly->values(),
            'preferred' => SwingTrust::preferred(),
            'watchOnlyNames' => config('swing.trust.watch_only'),
            'watchlist' => $this->groupBySymbol($signals->where('state', 'WATCHLIST')),
            'extended' => $this->groupBySymbol($signals->where('state', 'EXTENDED')),
            'regime' => $signals->first(),
            'families' => config('swing.agreement.families'),
            'minFamilies' => config('swing.agreement.min_families_agree'),
        ]);
    }

    public function api()
    {
        $scanDate = SwingSignal::max('scan_date');

        return response()->json([
            'label' => 'NOT TESTED',
            'caveats' => [
                'No swing strategy has been validated out of sample. Every threshold and scoring weight is a conventional starting value.',
                'The score is information only: in testing, picking the highest scores did no better than picking at random.',
                'Strategies in swing.trust.watch_only did no better than random stocks in testing and are logged on paper only.',
                'Entry, stop and target describe a plan, not a prediction.',
            ],
            'scan_date' => $scanDate,
            'signals' => $scanDate
                ? SwingSignal::forDate($scanDate)->orderByDesc('score')->get()
                : [],
        ]);
    }

    /** Every plan ever logged and how it ended, beside random entries under the same rules. */
    public function paper(Request $request, PaperTradeStatsService $stats)
    {
        $report = $stats->report();

        if ($request->expectsJson()) {
            return response()->json($report);
        }

        return view('swing.paper', [
            'report' => $report,
            'trades' => SwingPaperTrade::where('kind', SwingPaperTrade::SIGNAL)
                ->orderByDesc('signal_date')->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    /**
     * Which of tonight's stocks fit the portfolio limits, best first.
     *
     * Where the scan logged a decision that is the answer, because it is the one the paper
     * account acted on. Anything else is judged against what the account holds now.
     */
    protected function decisions($ready): array
    {
        $logged = SwingPaperTrade::where('kind', SwingPaperTrade::SIGNAL)
            ->whereNotNull('portfolio')
            ->whereIn('signal_date', $ready->pluck('best.signal_date')->filter()->map->toDateString()->unique())
            ->get()
            ->keyBy('symbol');

        $fresh = $this->limits->decide($ready->reject(fn ($s) => $logged->has($s->symbol))->map(fn ($s) => [
            'symbol' => $s->symbol, 'entry' => $s->best->entryLevel(), 'stop' => $s->best->stop_price,
        ])->values());

        return $logged->map(fn ($t) => ['take' => $t->portfolio === 'TAKEN', 'reason' => $t->portfolio_reason]
            + $this->limits->size($t->entry_trigger ?? $t->signal_price, $t->stop_price))->all() + $fresh;
    }

    /**
     * One card per stock, with every strategy that fired on it.
     *
     * Grouped because agreement is a property of the stock, not of a strategy, and because
     * a stock appearing seven times down a list reads as seven opportunities when it is
     * one — which is the same double-count the family grouping exists to prevent.
     *
     * The plan shown is the most trusted strategy's, and stocks are ordered the way the
     * paper account takes them (SwingTrust::rank). Watch-only strategies are listed on the
     * card but count for nothing; a stock with only those is `watchOnly`.
     */
    protected function groupBySymbol($signals)
    {
        return $signals->groupBy('symbol')->map(function ($rows) {
            $rows = SwingTrust::sort($rows, fn ($r) => $r->strategy);
            $trading = $rows->reject(fn ($r) => SwingTrust::isWatchOnly($r->strategy));
            $families = $trading->map(fn ($r) => SwingTrust::family($r->strategy))->unique();
            $best = $rows->first();

            return (object) [
                'symbol' => $best->symbol,
                'best' => $best,
                'signals' => $rows,
                'strategies' => $trading->count(),
                'families' => $families->count(),
                'familyNames' => $families->values()->all(),
                // Shown for information only; nothing is ranked by it
                'score' => (int) round($rows->avg('score')),
                // Independent evidence, not a headcount
                'qualifies' => $families->count() >= config('swing.agreement.min_families_agree'),
                'watchOnly' => $trading->isEmpty(),
                'rank' => SwingTrust::rank($trading->pluck('strategy')),
            ];
        })->sortByDesc('rank')->values();
    }
}
