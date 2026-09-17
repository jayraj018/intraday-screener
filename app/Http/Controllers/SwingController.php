<?php

namespace App\Http\Controllers;

use App\Models\SwingSignal;
use App\Services\Swing\StrategyAgreementService;

class SwingController extends Controller
{
    public function __construct(protected StrategyAgreementService $agreement)
    {
    }

    public function index()
    {
        $scanDate = SwingSignal::max('scan_date');
        $signals = $scanDate ? SwingSignal::forDate($scanDate)->orderByDesc('score')->get() : collect();

        return view('swing.index', [
            'scanDate' => $scanDate,
            'ready' => $this->groupBySymbol($signals->where('state', 'READY')),
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
                'Scores rank setups against each other; they are not a probability of profit.',
                'Entry, stop and target describe a plan, not a prediction.',
            ],
            'scan_date' => $scanDate,
            'signals' => $scanDate
                ? SwingSignal::forDate($scanDate)->orderByDesc('score')->get()
                : [],
        ]);
    }

    /**
     * One card per stock, with every strategy that fired on it.
     *
     * Grouped because agreement is a property of the stock, not of a strategy, and because
     * a stock appearing seven times down a list reads as seven opportunities when it is
     * one — which is the same double-count the family grouping exists to prevent.
     */
    protected function groupBySymbol($signals)
    {
        return $signals->groupBy('symbol')->map(function ($rows) {
            $families = $rows->map(fn ($r) => config("swing.agreement.families.{$r->strategy}", $r->strategy))->unique();
            $best = $rows->sortByDesc('score')->first();

            return (object) [
                'symbol' => $best->symbol,
                'best' => $best,
                'signals' => $rows->sortByDesc('score')->values(),
                'strategies' => $rows->count(),
                'families' => $families->count(),
                'familyNames' => $families->values()->all(),
                'score' => (int) round($rows->avg('score')),
                // Independent evidence, not a headcount
                'qualifies' => $families->count() >= config('swing.agreement.min_families_agree'),
            ];
        })->sortByDesc(fn ($s) => [$s->qualifies, $s->families, $s->score])->values();
    }
}
