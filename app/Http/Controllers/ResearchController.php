<?php

namespace App\Http\Controllers;

use App\Models\NseDailyBar;
use App\Models\ResearchSignal;
use App\Services\Research\GapHoldDeliveryDetector;
use App\Services\Research\ResearchResultsService;
use App\Services\Research\ResearchSignalTracker;
use Illuminate\Http\Request;

/**
 * Research dashboard (historical backtest evidence) and the research-watch pattern scanner.
 * Read-only: nothing here places, sizes or alerts a trade.
 */
class ResearchController extends Controller
{
    public function index(Request $request, ResearchResultsService $results)
    {
        $period = in_array($request->query('period'), ResearchResultsService::PERIODS, true) ? $request->query('period') : 'Full 2Y';
        $variant = $request->query('variant') === 'M' ? 'M' : 'base';
        $window = $request->query('window') === 'Recent 1Y' ? 'Recent 1Y' : 'Full 2Y';

        return view('research.index', [
            'available' => $results->available(),
            'period' => $period,
            'variant' => $variant,
            'window' => $window,
            'periods' => ResearchResultsService::PERIODS,
            'summary' => $results->summary(),
            'comparison' => $results->comparison($period, $variant),
            'ranking' => $results->ranking(),
            'portfolio' => $results->portfolio($window),
            'charts' => $results->charts(),
        ]);
    }

    public function signals(Request $request, ResearchResultsService $results, GapHoldDeliveryDetector $detector, ResearchSignalTracker $tracker)
    {
        $date = $request->query('date');
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        $scan = $detector->scan($date);
        $cfg = config('research.gap_hold_delivery');

        return view('research.signals', [
            'cfg' => $cfg,
            'scan' => $scan,
            'dataDates' => ['first' => NseDailyBar::min('date'), 'last' => NseDailyBar::max('date'), 'sessions' => NseDailyBar::distinct()->count('date')],
            'evidence' => $results->evidence($cfg['research_strategy']),
            'live' => $tracker->liveStats(),
            'history' => ResearchSignal::orderByDesc('signal_date')->limit(100)->get(),
        ]);
    }

    public function chart(string $name, ResearchResultsService $results)
    {
        $path = $results->chartPath($name);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=3600']);
    }
}
