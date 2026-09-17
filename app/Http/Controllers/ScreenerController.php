<?php

namespace App\Http\Controllers;

use App\Models\ScreenerResult;
use App\Models\StrategyStat;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Services\NseService;
use App\Services\ScreenerService;
use App\Services\StockDataService;
use App\Services\IndicatorService;

class ScreenerController extends Controller
{
    public function index(NseService $nse)
    {
        $results = ScreenerResult::where('scan_date', now()->toDateString())
            ->orderByDesc('volume_surge')
            ->get();

        $lastScanAt = $results->first()?->created_at;

        // Same trading-day window the scan adds board-meeting stocks from
        $meetings = collect($nse->boardMeetings(now()->subWeekday(), now()->addWeekday()));
        $eventBoard = collect([
            'Tomorrow' => now()->addWeekday(),
            'Today' => now(),
            'Yesterday' => now()->subWeekday(),
        ])->map(fn ($date) => [
            'date' => $date,
            'events' => $meetings->where('date', $date->toDateString())->values(),
        ]);

        $minAgree = config('screener.min_strategies_agree');
        $minWinRate = config('screener.min_win_rate');
        $minExpectancy = config('screener.min_expectancy_r');
        // Only what Top Picks needs to decide which strategies get a vote. The full
        // metrics live at /api/backtest-stats rather than on the dashboard.
        $strategyStats = StrategyStat::all()->keyBy('strategy');
        $qualifiedStrategies = $strategyStats->filter->qualifies()->sortByDesc('win_rate');

        // Only strategies that proved themselves in the backtest get a vote
        $topPicks = $this->buildTopPicks($results->whereIn('strategy', $qualifiedStrategies->keys()), $minAgree);

        return view('screener.index', compact('results', 'lastScanAt', 'eventBoard', 'topPicks', 'minAgree', 'minWinRate', 'minExpectancy', 'strategyStats', 'qualifiedStrategies'));
    }

    /**
     * Combine today's setups per stock and direction, keeping only stocks where at
     * least $minAgree different strategies point the same way.
     */
    protected function buildTopPicks($results, int $minAgree)
    {
        $isBuy = fn ($r) => str_contains($r->signal, 'BUY');

        return $results
            ->groupBy(fn ($r) => $r->symbol . '|' . ($isBuy($r) ? 'BUY' : 'SELL'))
            ->map(function ($rows, $key) use ($results, $isBuy) {
                [$symbol, $direction] = explode('|', $key);

                $opposing = $results->where('symbol', $symbol)
                    ->filter(fn ($r) => $isBuy($r) !== ($direction === 'BUY'))
                    ->pluck('strategy')->unique()->count();

                // ORB levels come from the intraday opening range; every other strategy
                // shares the same ATR-based levels off the daily close, so prefer those.
                $levels = $rows->firstWhere('strategy', '!=', 'ORB + VWAP Breakout') ?? $rows->first();

                return (object) [
                    'symbol' => $symbol,
                    'direction' => $direction,
                    'strategies' => $rows->pluck('strategy')->unique()->values(),
                    'score' => $rows->pluck('strategy')->unique()->count(),
                    'opposing' => $opposing,
                    'volume_surge' => $rows->contains('volume_surge', true),
                    'entry' => $levels->entry,
                    'stop_loss' => $levels->stop_loss,
                    'target' => $levels->target,
                ];
            })
            ->filter(fn ($pick) => $pick->score >= $minAgree)
            ->sortBy([['score', 'desc'], ['opposing', 'asc'], ['volume_surge', 'desc']])
            ->values();
    }

    public function api()
    {
        $results = ScreenerResult::where('scan_date', now()->toDateString())
            ->orderByDesc('volume_surge')
            ->get();

        return response()->json($results);
    }

    public function livePrices()
    {
        $results = ScreenerResult::where('scan_date', now()->toDateString())->get();
        $prices = $this->currentPrices($results->pluck('symbol')->map(fn ($s) => strtoupper($s))->unique()->values()->all());
        $liveData = [];

        foreach ($results as $result) {
            $currentPrice = $prices[strtoupper($result->symbol)] ?? null;

            if (! $currentPrice) {
                // Return fallback if fetch fails
                $liveData[$result->id] = [
                    'current' => $result->entry,
                    'change' => 0.0,
                    'change_percent' => 0.0,
                    'status' => 'Data Delayed',
                ];

                continue;
            }

            $change = $currentPrice - $result->entry;
            $changePercent = ($change / $result->entry) * 100;

            // Determine trade status
            $status = 'Active';
            if (str_contains($result->signal, 'BUY')) {
                if ($currentPrice <= $result->stop_loss) {
                    $status = 'SL Hit 🔴';
                } elseif ($currentPrice >= $result->target) {
                    $status = 'Target Hit 🟢';
                }
            } else { // SELL (short)
                if ($currentPrice >= $result->stop_loss) {
                    $status = 'SL Hit 🔴';
                } elseif ($currentPrice <= $result->target) {
                    $status = 'Target Hit 🟢';
                }
            }

            $liveData[$result->id] = [
                'current' => round($currentPrice, 2),
                'change' => round($change, 2),
                'change_percent' => round($changePercent, 2),
                'status' => $status,
            ];
        }

        return response()->json($liveData);
    }

    /**
     * Latest price per symbol. Fetched in parallel batches — one request at a time took
     * minutes once the scan covered hundreds of stocks — and cached briefly so the
     * dashboard's 30-second polling doesn't refetch everything on every call.
     */
    protected function currentPrices(array $symbols): array
    {
        return Cache::remember('screener.live_prices.' . md5(implode(',', $symbols)), now()->addSeconds(20), function () use ($symbols) {
            $prices = [];

            foreach (array_chunk($symbols, 25) as $batch) {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn ($symbol) => $pool->as($symbol)->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])->timeout(5)->get("https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}.NS", [
                        'range' => '1d',
                        'interval' => '1m',
                    ]),
                    $batch
                ));

                foreach ($responses as $symbol => $response) {
                    // A request that failed outright comes back as an exception, not a response
                    if ($response instanceof Response && $response->successful()) {
                        $prices[$symbol] = $response->json('chart.result.0.meta.regularMarketPrice');
                    }
                }
            }

            return array_filter($prices);
        });
    }

    public function analyze(Request $request, StockDataService $dataService, IndicatorService $indicators, ScreenerService $screener)
    {
        $symbol = str_replace(' ', '', strtoupper($request->query('symbol')));
        if (!$symbol) {
            return response()->json(['error' => 'Please enter a stock symbol.'], 400);
        }

        $yahooSymbol = $symbol . '.NS';
        $allCandles = $dataService->getDailyCandles($symbol);

        // Today's candle is still forming during the session: its "close" is the live
        // price and its volume is only what has traded so far. Reading it as finished
        // made every setup's entry change on each refresh, so the indicators below run on
        // completed candles only — while the live price is kept for display.
        $candles = $allCandles ? $screener->completedDailyCandles($allCandles) : $allCandles;
        $sessionRunning = $allCandles && count($candles) < count($allCandles);

        if (!$candles) {
            // Distinguish a genuinely unknown ticker from a transient upstream failure —
            // reporting "not found on NSE" for a network blip sends people hunting for
            // the wrong problem.
            return match ($dataService->lastError()) {
                'not_found' => response()->json(['error' => "'{$symbol}' is not a listed NSE symbol. Check the spelling, or the ticker may have changed after a merger/demerger."], 404),
                default => response()->json(['error' => "Couldn't reach the market data provider for '{$symbol}'. This is usually temporary — please try again in a moment."], 503),
            };
        }

        if (count($candles) < 50) {
            return response()->json(['error' => "'{$symbol}' has only " . count($candles) . " days of history available; at least 50 are needed for these indicators. Recently listed stocks won't have enough data yet."], 422);
        }

        $closes = array_column($candles, 'close');
        $latest = end($candles);
        $closeNow = $latest['close'];
        $closePrev = $candles[count($candles) - 2]['close'];

        // Mid-session the price on screen is today's live price, measured against the last
        // completed close. After the close both come from finished candles instead.
        $livePrice = $sessionRunning ? end($allCandles)['close'] : $closeNow;
        $referenceClose = $sessionRunning ? $closeNow : $closePrev;

        // Every strategy, indicator period and stop distance comes from the same place the
        // screener uses. These were re-implemented here with 9, 21, 1.5 and 2.0 hardcoded,
        // so changing config/screener.php moved the screener and left this page behind —
        // the same stock could show one set of levels here and another on the dashboard.
        //
        // dailyContext() is asked to skip its liquidity floor: that filter decides which
        // stocks are worth scanning, and someone searching a specific symbol has already
        // made that choice. The strategy rules themselves are identical.
        $ctx = $screener->dailyContext($candles, requireLiquidity: false);
        $setups = $ctx ? $screener->dailySetups($symbol, $candles, $ctx) : [];

        $health = $screener->technicalHealth($candles);
        $indicatorDetails = $health['indicators'];
        $bullishCount = $health['bullish'];
        $bearishCount = $health['bearish'];
        $bias = $health['bias'];

        // Query Yahoo Finance Search for news
        $newsList = [];
        $hasQuarterlyResult = false;
        $resultSentiment = 'Neutral';

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(5)->get("https://query2.finance.yahoo.com/v1/finance/search", [
                'q' => $yahooSymbol,
                'newsCount' => 5,
            ]);

            if ($response->successful()) {
                $news = $response->json('news') ?? [];
                foreach ($news as $item) {
                    $title = $item['title'] ?? '';
                    $publishTime = $item['providerPublishTime'] ?? time();
                    
                    // Highlight quarter/earnings results news
                    $isQuarterNews = false;
                    $titleLower = strtolower($title);
                    if (str_contains($titleLower, 'q1') || str_contains($titleLower, 'q2') || 
                        str_contains($titleLower, 'q3') || str_contains($titleLower, 'q4') || 
                        str_contains($titleLower, 'quarter') || str_contains($titleLower, 'earnings') || 
                        str_contains($titleLower, 'financial result') || str_contains($titleLower, 'profit') ||
                        str_contains($titleLower, 'net profit') || str_contains($titleLower, 'revenue')) {
                        
                        $isQuarterNews = true;
                        $hasQuarterlyResult = true;

                        // Check sentiment of results
                        if (str_contains($titleLower, 'rises') || str_contains($titleLower, 'up') || 
                            str_contains($titleLower, 'jump') || str_contains($titleLower, 'beats') || 
                            str_contains($titleLower, 'surge') || str_contains($titleLower, 'grow')) {
                            $resultSentiment = 'Positive / Good';
                        } elseif (str_contains($titleLower, 'falls') || str_contains($titleLower, 'down') || 
                                  str_contains($titleLower, 'slips') || str_contains($titleLower, 'misses') || 
                                  str_contains($titleLower, 'drop') || str_contains($titleLower, 'decline')) {
                            $resultSentiment = 'Negative / Bad';
                        }
                    }

                    $newsList[] = [
                        'title' => $title,
                        'link' => $item['link'] ?? '#',
                        'publisher' => $item['publisher'] ?? 'Yahoo Finance',
                        'time' => date('d M Y, h:i A', $publishTime),
                        'is_quarter' => $isQuarterNews,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Silence exception
        }

        // "Can I trade this now?" — the only panel whose entry price is one you can
        // still get, because it is measured against the latest candle rather than the
        // candle a setup triggered on earlier in the session.
        // Several sessions, not one: the 20 EMA runs continuously across days, so a
        // single-day fetch left it undefined until 20 candles had printed — no verdict
        // before ~10:55, which is most of the morning.
        $intradayCandles = $dataService->getIntradayCandles($symbol, '5d') ?? [];
        $tradeNow = $screener->liveTradeDecision($intradayCandles);
        $todayCandles = $screener->latestSession($intradayCandles);

        // ORB reads one session's opening range, so it gets today's candles only
        $orbSetup = null;
        if (count($todayCandles) >= 4) {
            $orbSetup = $screener->liveOrbSetup($todayCandles, $indicators->vwap($todayCandles));
        }

        return response()->json([
            'symbol' => $symbol,
            'current_price' => round($livePrice, 2),
            'change_percent' => round(($livePrice - $referenceClose) / $referenceClose * 100, 2),

            // The close every indicator below was computed from. During the session this
            // is yesterday's, which is why a setup's entry won't match the live price.
            'signal_close' => round($closeNow, 2),
            'signal_close_date' => $latest['date'],
            'session_running' => $sessionRunning,
            'bias' => $bias,
            'bullish_indicators' => $bullishCount,
            'bearish_indicators' => $bearishCount,
            'indicators' => $indicatorDetails,
            'setups' => $setups,
            'has_quarterly_result' => $hasQuarterlyResult,
            'result_sentiment' => $resultSentiment,
            'news' => $newsList,
            'trade_now' => $tradeNow,
            'orb_setup' => $orbSetup,
        ]);
    }

}
