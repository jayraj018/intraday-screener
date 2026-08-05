<?php

namespace App\Http\Controllers;

use App\Models\ScreenerResult;
use Illuminate\Http\Request;
use App\Services\StockDataService;
use App\Services\IndicatorService;

class ScreenerController extends Controller
{
    public function index()
    {
        $results = ScreenerResult::where('scan_date', now()->toDateString())
            ->orderByDesc('volume_surge')
            ->get();

        $lastScanAt = $results->first()?->created_at;

        $earningsYesterday = [
            ['symbol' => 'BSE', 'name' => 'BSE Limited'],
            ['symbol' => 'BHARTIHEXA', 'name' => 'Bharti Hexacom'],
            ['symbol' => 'UGROCAP', 'name' => 'UGRO Capital'],
            ['symbol' => 'SAREGAMA', 'name' => 'Saregama India'],
            ['symbol' => 'VIVIANA', 'name' => 'Viviana Power Tech'],
        ];

        $earningsToday = [
            ['symbol' => 'POWERGRID', 'name' => 'Power Grid Corp'],
            ['symbol' => 'AUROPHARMA', 'name' => 'Aurobindo Pharma'],
            ['symbol' => 'CUMMINSIND', 'name' => 'Cummins India'],
            ['symbol' => 'BIOCON', 'name' => 'Biocon'],
            ['symbol' => 'BERGERPAINT', 'name' => 'Berger Paints'],
            ['symbol' => 'BIKAJI', 'name' => 'Bikaji Foods'],
            ['symbol' => 'GODREJAGRO', 'name' => 'Godrej Agrovet'],
            ['symbol' => 'GNFC', 'name' => 'GNFC'],
            ['symbol' => 'BAYERCROP', 'name' => 'Bayer CropScience'],
            ['symbol' => 'NAVINFLUOR', 'name' => 'Navin Fluorine'],
            ['symbol' => 'NEULANDLAB', 'name' => 'Neuland Labs'],
            ['symbol' => 'WHIRLPOOL', 'name' => 'Whirlpool India'],
            ['symbol' => 'JKLAKSHMI', 'name' => 'JK Lakshmi Cement'],
            ['symbol' => 'SNOWMAN', 'name' => 'Snowman Logistics'],
        ];

        $earningsTomorrow = [
            ['symbol' => 'HEROMOTOCO', 'name' => 'Hero MotoCorp'],
            ['symbol' => 'TRENT', 'name' => 'Trent Limited'],
        ];

        return view('screener.index', compact('results', 'lastScanAt', 'earningsYesterday', 'earningsToday', 'earningsTomorrow'));
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
        $liveData = [];

        foreach ($results as $result) {
            $yahooSymbol = strtoupper($result->symbol) . '.NS';
            
            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])->timeout(5)->get("https://query1.finance.yahoo.com/v8/finance/chart/{$yahooSymbol}", [
                    'range' => '1d',
                    'interval' => '1m',
                ]);

                $currentPrice = null;
                if ($response->successful()) {
                    $meta = $response->json('chart.result.0.meta');
                    $currentPrice = $meta['regularMarketPrice'] ?? null;
                }

                if ($currentPrice) {
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
            } catch (\Exception $e) {
                // Return fallback if fetch fails
                $liveData[$result->id] = [
                    'current' => $result->entry,
                    'change' => 0.0,
                    'change_percent' => 0.0,
                    'status' => 'Data Delayed',
                ];
            }
        }

        return response()->json($liveData);
    }

    public function analyze(Request $request, StockDataService $dataService, IndicatorService $indicators)
    {
        $symbol = str_replace(' ', '', strtoupper($request->query('symbol')));
        if (!$symbol) {
            return response()->json(['error' => 'Please enter a stock symbol.'], 400);
        }

        $yahooSymbol = $symbol . '.NS';
        $candles = $dataService->getDailyCandles($symbol);

        if (!$candles || count($candles) < 50) {
            return response()->json(['error' => "Not enough historical data or stock '{$symbol}' not found on NSE."], 404);
        }

        $closes = array_column($candles, 'close');
        $latest = end($candles);
        $closeNow = $latest['close'];
        $closePrev = $candles[count($candles) - 2]['close'];

        $avgVolume = $indicators->averageVolume($candles);
        $atr = $indicators->atr($candles, 14);

        $closesPrev = array_slice($closes, 0, -1);

        $indicatorDetails = [];
        $setups = [];
        $bullishCount = 0;
        $bearishCount = 0;

        // 1. MA Crossover Check
        $shortMaNow = $indicators->sma($closes, 9);
        $longMaNow = $indicators->sma($closes, 21);
        $shortMaPrev = $indicators->sma($closesPrev, 9);
        $longMaPrev = $indicators->sma($closesPrev, 21);

        $maStatus = 'Neutral';
        $maDetail = 'Short MA is flat';
        if ($shortMaNow && $longMaNow) {
            if ($shortMaNow > $longMaNow) {
                $maStatus = 'Bullish';
                $maDetail = '9 SMA is trending above 21 SMA';
                $bullishCount++;
            } else {
                $maStatus = 'Bearish';
                $maDetail = '9 SMA is trending below 21 SMA';
                $bearishCount++;
            }
            // Check crossover trigger
            if ($shortMaPrev <= $longMaPrev && $shortMaNow > $longMaNow) {
                $setups[] = $this->buildAnalysisSetup('MA Crossover', 'BUY', $closeNow, $atr, 1.5, 2.0, '9 SMA crossed above 21 SMA today');
            } elseif ($shortMaPrev >= $longMaPrev && $shortMaNow < $longMaNow) {
                $setups[] = $this->buildAnalysisSetup('MA Crossover', 'SELL (short)', $closeNow, $atr, 1.5, 2.0, '9 SMA crossed below 21 SMA today');
            }
        }
        $indicatorDetails['ma'] = ['status' => $maStatus, 'detail' => $maDetail, 'short_val' => round($shortMaNow, 2), 'long_val' => round($longMaNow, 2)];

        // 2. RSI Check
        $rsiNow = $indicators->rsi($closes);
        $rsiPrev = $indicators->rsi($closesPrev);
        $rsiStatus = 'Neutral';
        $rsiDetail = 'RSI is in healthy territory';
        if ($rsiNow) {
            if ($rsiNow < 30) {
                $rsiStatus = 'Oversold / Reversal Watch';
                $rsiDetail = 'RSI is deeply oversold (< 30). Watch for pullback';
                $bullishCount++; // oversold is bullish reversal watch
            } elseif ($rsiNow > 70) {
                $rsiStatus = 'Overbought / Reversal Watch';
                $rsiDetail = 'RSI is overbought (> 70). Risk of correction';
                $bearishCount++;
            } else {
                if ($rsiNow > $rsiPrev) {
                    $rsiDetail = 'RSI is neutral and rising';
                } else {
                    $rsiDetail = 'RSI is neutral and falling';
                }
            }
            // Check triggers
            if ($rsiPrev <= 30 && $rsiNow > 30) {
                $setups[] = $this->buildAnalysisSetup('RSI Reversal', 'BUY', $closeNow, $atr, 1.5, 2.0, 'RSI crossed back above 30 (bullish recovery)');
            } elseif ($rsiPrev >= 70 && $rsiNow < 70) {
                $setups[] = $this->buildAnalysisSetup('RSI Reversal', 'SELL (short)', $closeNow, $atr, 1.5, 2.0, 'RSI slipped back below 70 (bearish correction)');
            }
        }
        $indicatorDetails['rsi'] = ['status' => $rsiStatus, 'detail' => $rsiDetail, 'value' => round($rsiNow, 2)];

        // 3. Bollinger Bands Check
        $bbNow = $indicators->bollingerBands($closes);
        $bbPrev = $indicators->bollingerBands($closesPrev);
        $bbStatus = 'Neutral';
        $bbDetail = 'Price is inside standard volatility bands';
        if ($bbNow) {
            if ($closeNow > $bbNow['upper']) {
                $bbStatus = 'Bullish Breakout';
                $bbDetail = 'Price closed outside Upper Bollinger Band';
                $bullishCount++;
            } elseif ($closeNow < $bbNow['lower']) {
                $bbStatus = 'Bearish Breakdown';
                $bbDetail = 'Price closed outside Lower Bollinger Band';
                $bearishCount++;
            }
            // Crossovers
            if ($closePrev <= $bbPrev['upper'] && $closeNow > $bbNow['upper']) {
                $setups[] = $this->buildAnalysisSetup('Bollinger Bands Breakout', 'BUY', $closeNow, $atr, 1.5, 2.0, 'Price broke above Upper Bollinger Band today');
            } elseif ($closePrev >= $bbPrev['lower'] && $closeNow < $bbNow['lower']) {
                $setups[] = $this->buildAnalysisSetup('Bollinger Bands Breakout', 'SELL (short)', $closeNow, $atr, 1.5, 2.0, 'Price broke below Lower Bollinger Band today');
            }
        }
        $indicatorDetails['bb'] = [
            'status' => $bbStatus, 
            'detail' => $bbDetail, 
            'upper' => round($bbNow['upper'], 2), 
            'middle' => round($bbNow['middle'], 2), 
            'lower' => round($bbNow['lower'], 2)
        ];

        // 4. MACD Check
        $macdData = $indicators->macd($closes);
        $macdStatus = 'Neutral';
        $macdDetail = 'MACD is neutral';
        if ($macdData) {
            if ($macdData['macd_now'] > $macdData['signal_now']) {
                $macdStatus = 'Bullish';
                $macdDetail = 'MACD line is above signal line';
                $bullishCount++;
            } else {
                $macdStatus = 'Bearish';
                $macdDetail = 'MACD line is below signal line';
                $bearishCount++;
            }
            // Check crossover
            if ($macdData['macd_prev'] <= $macdData['signal_prev'] && $macdData['macd_now'] > $macdData['signal_now']) {
                $setups[] = $this->buildAnalysisSetup('MACD Crossover', 'BUY', $closeNow, $atr, 1.5, 2.0, 'MACD line crossed above signal line today');
            } elseif ($macdData['macd_prev'] >= $macdData['signal_prev'] && $macdData['macd_now'] < $macdData['signal_now']) {
                $setups[] = $this->buildAnalysisSetup('MACD Crossover', 'SELL (short)', $closeNow, $atr, 1.5, 2.0, 'MACD line crossed below signal line today');
            }
        }
        $indicatorDetails['macd'] = [
            'status' => $macdStatus, 
            'detail' => $macdDetail, 
            'macd' => round($macdData['macd_now'] ?? 0, 2), 
            'signal' => round($macdData['signal_now'] ?? 0, 2)
        ];

        // 5. Volume Surge / Breakout Check
        $volStatus = 'Normal';
        $volDetail = 'Volume is in normal average range';
        $isVolSurge = $latest['volume'] >= ($avgVolume * 1.5);
        if ($isVolSurge) {
            $volStatus = 'Volume Surge';
            $volDetail = 'Trading volume is ' . round($latest['volume'] / $avgVolume, 1) . 'x above average';
            if ($closeNow > $closePrev) {
                $bullishCount++;
            } else {
                $bearishCount++;
            }
            if ($latest['volume'] >= ($avgVolume * 2.5)) {
                $type = $closeNow > $closePrev ? 'BUY' : 'SELL (short)';
                $reason = $closeNow > $closePrev ? 'Price closed positive with massive volume breakout' : 'Price closed negative with massive volume breakdown';
                $setups[] = $this->buildAnalysisSetup('Volume Breakout', $type, $closeNow, $atr, 1.5, 2.0, $reason);
            }
        }
        $indicatorDetails['volume'] = ['status' => $volStatus, 'detail' => $volDetail, 'current' => $latest['volume'], 'avg' => round($avgVolume, 0)];

        // Compute technical bias
        $bias = 'NEUTRAL';
        if ($bullishCount >= 3) $bias = 'BULLISH';
        if ($bearishCount >= 3) $bias = 'BEARISH';

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

        // Intraday VWAP & 20 EMA Strategy Run
        $intradaySetup = null;
        $intradayCandles = $dataService->getIntradayCandles($symbol);
        if ($intradayCandles && count($intradayCandles) >= 20) {
            $intraCloses = array_column($intradayCandles, 'close');
            $intraLatest = end($intradayCandles);
            $intraPrice = $intraLatest['close'];

            // 1. VWAP
            $vwapArray = $indicators->vwap($intradayCandles);
            $vwapNow = end($vwapArray);

            // 2. 20 EMA
            $ema20Array = $indicators->emaArray($intraCloses, 20);
            $ema20Now = end($ema20Array);

            // 3. Intraday Volume (20-period average on 5m chart)
            $intraAvgVol = $indicators->averageVolume($intradayCandles, 20);
            $intraVolNow = $intraLatest['volume'];

            if ($vwapNow && $ema20Now && $intraAvgVol) {
                $isAboveVwap = $intraPrice > $vwapNow;
                $isAboveEma20 = $intraPrice > $ema20Now;
                $isHighVol = $intraVolNow > ($intraAvgVol * 1.2);

                $decision = 'NO BUY / HOLD';
                $reason = 'Stock is currently trading below VWAP or 20 EMA, indicating lack of intraday momentum.';
                $setupAction = 'WAIT';

                if ($isAboveVwap && $isAboveEma20) {
                    $decision = 'BUY (High Momentum)';
                    $setupAction = 'BUY';
                    $reason = 'Stock is trading above both VWAP and 20 EMA on the 5-minute chart, indicating strong institutional buy pressure.';
                    if ($isHighVol) {
                        $reason .= ' Confirmed by above-average intraday volume.';
                    } else {
                        $reason .= ' However, volume is standard; watch for a volume surge.';
                    }
                } elseif ($intraPrice > $vwapNow && $intraPrice <= $ema20Now) {
                    $reason = 'Stock is above VWAP but below 20 EMA. Wait for price to break above the 20 EMA (₹' . round($ema20Now, 2) . ') for confirmation.';
                } elseif ($intraPrice <= $vwapNow && $intraPrice > $ema20Now) {
                    $reason = 'Stock is above 20 EMA but below VWAP. Avoid buying until price breaks above VWAP (₹' . round($vwapNow, 2) . ') to avoid resistance.';
                }

                $sl = round(min($vwapNow, $ema20Now) * 0.995, 2);
                $risk = $intraPrice - $sl;
                $tgt = round($intraPrice + ($risk * 2), 2);

                $intradaySetup = [
                    'price' => round($intraPrice, 2),
                    'vwap' => round($vwapNow, 2),
                    'ema20' => round($ema20Now, 2),
                    'volume' => $intraVolNow,
                    'avg_volume' => round($intraAvgVol, 0),
                    'decision' => $decision,
                    'action' => $setupAction,
                    'reason' => $reason,
                    'stop_loss' => $sl,
                    'target' => $tgt,
                ];
            }
        }

        // ORB + VWAP Breakout Strategy Check
        $orbSetup = null;
        if ($intradayCandles && count($intradayCandles) >= 4) {
            $vwapArray = $indicators->vwap($intradayCandles);
            $screenerService = app(\App\Services\ScreenerService::class);
            $orbSetup = $screenerService->calculateOrbSetup($intradayCandles, $vwapArray);
        }

        return response()->json([
            'symbol' => $symbol,
            'current_price' => round($closeNow, 2),
            'change_percent' => round((($closeNow - $closePrev) / $closePrev) * 100, 2),
            'bias' => $bias,
            'bullish_indicators' => $bullishCount,
            'bearish_indicators' => $bearishCount,
            'indicators' => $indicatorDetails,
            'setups' => $setups,
            'has_quarterly_result' => $hasQuarterlyResult,
            'result_sentiment' => $resultSentiment,
            'news' => $newsList,
            'intraday_setup' => $intradaySetup,
            'orb_setup' => $orbSetup,
        ]);
    }

    protected function buildAnalysisSetup(string $strategy, string $signal, float $entry, float $atr, float $slMultiplier, float $rrRatio, string $reason): array
    {
        $riskDistance = $atr * $slMultiplier;
        $stopLoss = str_contains($signal, 'BUY') ? round($entry - $riskDistance, 2) : round($entry + $riskDistance, 2);
        $target = str_contains($signal, 'BUY') ? round($entry + ($riskDistance * $rrRatio), 2) : round($entry - ($riskDistance * $rrRatio), 2);

        return [
            'strategy' => $strategy,
            'signal' => $signal,
            'entry' => round($entry, 2),
            'stop_loss' => $stopLoss,
            'target' => $target,
            'reason' => $reason,
        ];
    }
}
