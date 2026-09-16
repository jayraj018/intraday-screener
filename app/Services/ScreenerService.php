<?php

namespace App\Services;

class ScreenerService
{
    public function __construct(
        protected StockDataService $dataService,
        protected IndicatorService $indicators,
        protected NseService $nse
    ) {
    }

    /**
     * Stocks the scan covers, rebuilt on every run: the configured NSE index, every
     * company with a board meeting from the previous to the next trading day, and any
     * extra symbols from config.
     */
    public function watchlist(): array
    {
        $meetings = $this->nse->boardMeetings(now()->subWeekday(), now()->addWeekday());

        $symbols = array_merge(
            $this->nse->indexSymbols(config('screener.universe_index')),
            array_column($meetings, 'symbol'),
            config('screener.watchlist'),
        );

        return array_values(array_unique(array_map('strtoupper', $symbols)));
    }

    /**
     * Screen the configured watchlist and return ranked setups.
     *
     * Strategy: moving average crossover, confirmed by a volume surge, with
     * stop-loss and target sized off the stock's own ATR (volatility) rather
     * than an arbitrary fixed percentage.
     *
     * This is rule-based technical screening only. It reflects what already
     * happened on the chart (the crossover), not a prediction of what happens
     * next — treat every result as a candidate to verify yourself, not a
     * guaranteed winner.
     */
    public function run(): array
    {
        $slMultiplier = config('screener.stop_loss_atr_multiplier');
        $rrRatio = config('screener.risk_reward_ratio');

        $results = [];

        foreach ($this->watchlist() as $symbol) {
            $candles = $this->dataService->getDailyCandles($symbol);
            $ctx = $candles ? $this->dailyContext($candles) : null;

            if (! $ctx) {
                continue;
            }

            ['close_now' => $closeNow, 'atr' => $atr, 'volume_surge' => $volumeSurge] = $ctx;

            // 1–5 and 8: strategies that only need daily candles
            array_push($results, ...$this->dailySetups($symbol, $candles, $ctx));

            // 6. Positive Earnings Results Check (Announced in the last 48 hours)
            try {
                $yahooSymbol = strtoupper($symbol) . '.NS';
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])->timeout(3)->get("https://query2.finance.yahoo.com/v1/finance/search", [
                    'q' => $yahooSymbol,
                    'newsCount' => 3,
                ]);

                if ($response->successful()) {
                    $news = $response->json('news') ?? [];
                    foreach ($news as $item) {
                        $title = $item['title'] ?? '';
                        $publishTime = $item['providerPublishTime'] ?? 0;

                        // Check if article is published in last 48 hours (172800 seconds)
                        if ($publishTime >= (time() - 172800)) {
                            $titleLower = strtolower($title);
                            $isQuarterNews = str_contains($titleLower, 'q1') || str_contains($titleLower, 'q2') ||
                                             str_contains($titleLower, 'q3') || str_contains($titleLower, 'q4') ||
                                             str_contains($titleLower, 'quarter') || str_contains($titleLower, 'earnings') ||
                                             str_contains($titleLower, 'financial result') || str_contains($titleLower, 'profit') ||
                                             str_contains($titleLower, 'net profit') || str_contains($titleLower, 'revenue');

                            if ($isQuarterNews) {
                                // Verify positive sentiment
                                $isPositive = str_contains($titleLower, 'rises') || str_contains($titleLower, 'up') ||
                                              str_contains($titleLower, 'jump') || str_contains($titleLower, 'beats') ||
                                              str_contains($titleLower, 'surge') || str_contains($titleLower, 'grow') ||
                                              str_contains($titleLower, 'gain') || str_contains($titleLower, 'climbs');

                                if ($isPositive) {
                                    // Fetch intraday 5m data to verify if it is an actual BUY
                                    $intradayCandles = $this->dataService->getIntradayCandles($symbol);
                                    if ($intradayCandles && count($intradayCandles) >= 20) {
                                        $intraCloses = array_column($intradayCandles, 'close');
                                        $intraLatest = end($intradayCandles);
                                        $intraPrice = $intraLatest['close'];

                                        // Calculate VWAP & 20 EMA
                                        $vwapArray = $this->indicators->vwap($intradayCandles);
                                        $vwapNow = end($vwapArray);

                                        $ema20Array = $this->indicators->emaArray($intraCloses, 20);
                                        $ema20Now = end($ema20Array);

                                        // Only add setup if price is above both VWAP and 20 EMA!
                                        if ($vwapNow && $ema20Now && $intraPrice > $vwapNow && $intraPrice > $ema20Now) {
                                            $results[] = $this->buildSetup($symbol, 'Positive Earnings', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'below', 'Good result: ' . $title . ' (Confirmed above VWAP & 20 EMA)');
                                        }
                                    }
                                    break; // Only check one news article per stock
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore news fetch exceptions to prevent breaking the screener run
            }

            // 7. ORB + VWAP Breakout Strategy Check
            $intradayCandles = $this->dataService->getIntradayCandles($symbol);
            if ($intradayCandles && count($intradayCandles) >= 4) {
                $vwapArray = $this->indicators->vwap($intradayCandles);
                $orbSetup = $this->calculateOrbSetup($intradayCandles, $vwapArray);
                if ($orbSetup) {
                    $results[] = [
                        'symbol' => $symbol,
                        'strategy' => 'ORB + VWAP Breakout',
                        'signal' => $orbSetup['type'] === 'BUY (Long)' ? 'BUY' : 'SELL (short)',
                        'entry' => $orbSetup['entry'],
                        'stop_loss' => $orbSetup['stop_loss'],
                        'target' => $orbSetup['target'],
                        'volume_surge' => $volumeSurge,
                        'confidence' => $volumeSurge ? 'higher' : 'moderate',
                        'reason' => $orbSetup['reason'],
                    ];
                }
            }
        }

        // Sort by volume surge (confirmed setups first)
        usort($results, fn ($a, $b) => $b['volume_surge'] <=> $a['volume_surge']);

        return $results;
    }

    /**
     * Values every daily-candle strategy shares, or null when the stock should be
     * skipped (not enough history, or too illiquid to trade).
     */
    public function dailyContext(array $candles): ?array
    {
        // Need enough history for EMA 26 + Signal 9 = ~35 days minimum, let's require at least 50 candles
        if (count($candles) < 50) {
            return null;
        }

        $latest = end($candles);
        $avgVolume = $this->indicators->averageVolume($candles);
        $atr = $this->indicators->atr($candles, config('screener.atr_period'));

        if (! $avgVolume || ! $atr) {
            return null;
        }

        if ($avgVolume < config('screener.min_avg_volume')) {
            return null; // skip illiquid stocks
        }

        return [
            'close_now' => $latest['close'],
            'close_prev' => $candles[count($candles) - 2]['close'],
            'avg_volume' => $avgVolume,
            'atr' => $atr,
            'volume_surge' => $latest['volume'] >= ($avgVolume * config('screener.volume_surge_multiplier')),
        ];
    }

    /**
     * Setups from the strategies that only need daily candles (MA, RSI, Bollinger,
     * MACD, volume breakout, high momentum). The backtest replays these on past candles.
     */
    public function dailySetups(string $symbol, array $candles, array $ctx): array
    {
        $shortPeriod = config('screener.short_ma');
        $longPeriod = config('screener.long_ma');
        $slMultiplier = config('screener.stop_loss_atr_multiplier');
        $rrRatio = config('screener.risk_reward_ratio');

        [
            'close_now' => $closeNow,
            'close_prev' => $closePrev,
            'avg_volume' => $avgVolume,
            'atr' => $atr,
            'volume_surge' => $volumeSurge,
        ] = $ctx;

        $latest = end($candles);
        $closes = array_column($candles, 'close');

        // Prepare previous closes for prior indicators
        $closesPrev = array_slice($closes, 0, -1);

        $results = [];

        // 1. Moving Average Crossover
        $shortMaNow = $this->indicators->sma($closes, $shortPeriod);
        $longMaNow = $this->indicators->sma($closes, $longPeriod);
        $shortMaPrev = $this->indicators->sma($closesPrev, $shortPeriod);
        $longMaPrev = $this->indicators->sma($closesPrev, $longPeriod);

        if ($shortMaNow && $longMaNow && $shortMaPrev && $longMaPrev) {
            if ($shortMaPrev <= $longMaPrev && $shortMaNow > $longMaNow) {
                $results[] = $this->buildSetup($symbol, 'MA Crossover', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'below', '9 SMA crossed above 21 SMA');
            } elseif ($shortMaPrev >= $longMaPrev && $shortMaNow < $longMaNow) {
                $results[] = $this->buildSetup($symbol, 'MA Crossover', 'SELL (short)', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'above', '9 SMA crossed below 21 SMA');
            }
        }

        // 2. RSI Reversal
        $rsiNow = $this->indicators->rsi($closes);
        $rsiPrev = $this->indicators->rsi($closesPrev);
        if ($rsiNow && $rsiPrev) {
            if ($rsiPrev <= 30 && $rsiNow > 30) {
                $results[] = $this->buildSetup($symbol, 'RSI Reversal', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'below', 'RSI recovered from oversold territory (< 30)');
            } elseif ($rsiPrev >= 70 && $rsiNow < 70) {
                $results[] = $this->buildSetup($symbol, 'RSI Reversal', 'SELL (short)', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'above', 'RSI retreated from overbought territory (> 70)');
            }
        }

        // 3. Bollinger Bands Breakout
        $bbNow = $this->indicators->bollingerBands($closes);
        $bbPrev = $this->indicators->bollingerBands($closesPrev);
        if ($bbNow && $bbPrev) {
            if ($closePrev <= $bbPrev['upper'] && $closeNow > $bbNow['upper']) {
                $results[] = $this->buildSetup($symbol, 'Bollinger Bands Breakout', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'below', 'Price broke out above the Upper Bollinger Band');
            } elseif ($closePrev >= $bbPrev['lower'] && $closeNow < $bbNow['lower']) {
                $results[] = $this->buildSetup($symbol, 'Bollinger Bands Breakout', 'SELL (short)', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'above', 'Price broke down below the Lower Bollinger Band');
            }
        }

        // 4. MACD Crossover
        $macdData = $this->indicators->macd($closes);
        if ($macdData) {
            $macdNow = $macdData['macd_now'];
            $signalNow = $macdData['signal_now'];
            $macdPrev = $macdData['macd_prev'];
            $signalPrev = $macdData['signal_prev'];

            if ($macdPrev <= $signalPrev && $macdNow > $signalNow) {
                $results[] = $this->buildSetup($symbol, 'MACD Crossover', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'below', 'MACD line crossed above Signal line');
            } elseif ($macdPrev >= $signalPrev && $macdNow < $signalNow) {
                $results[] = $this->buildSetup($symbol, 'MACD Crossover', 'SELL (short)', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'above', 'MACD line crossed below Signal line');
            }
        }

        // 5. Volume Breakout
        if ($latest['volume'] >= ($avgVolume * 2.5)) {
            if ($closeNow > $closePrev) {
                $results[] = $this->buildSetup($symbol, 'Volume Breakout', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, true, 'below', 'Price closed positive with extreme volume surge (> 2.5x avg)');
            } else {
                $results[] = $this->buildSetup($symbol, 'Volume Breakout', 'SELL (short)', $closeNow, $atr, $slMultiplier, $rrRatio, true, 'above', 'Price closed negative with extreme volume surge (> 2.5x avg)');
            }
        }

        // 8. High Momentum Gainer/Loser Strategy Check
        $changePercent = (($closeNow - $closePrev) / $closePrev) * 100;
        if ($changePercent >= 3.0) {
            $results[] = $this->buildSetup($symbol, 'High Momentum', 'BUY', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'below', "Stock surged by " . round($changePercent, 2) . "% from its previous close, showing strong daily momentum");
        } elseif ($changePercent <= -3.0) {
            $results[] = $this->buildSetup($symbol, 'High Momentum', 'SELL (short)', $closeNow, $atr, $slMultiplier, $rrRatio, $volumeSurge, 'above', "Stock plummeted by " . round(abs($changePercent), 2) . "% from its previous close, showing strong selling pressure");
        }

        return $results;
    }

    protected function buildSetup(
        string $symbol,
        string $strategy,
        string $signal,
        float $entry,
        float $atr,
        float $slMultiplier,
        float $rrRatio,
        bool $volumeSurge,
        string $stopDirection,
        string $details
    ): array {
        $riskDistance = $atr * $slMultiplier;

        if ($stopDirection === 'below') {
            $stopLoss = round($entry - $riskDistance, 2);
            $target = round($entry + ($riskDistance * $rrRatio), 2);
        } else {
            $stopLoss = round($entry + $riskDistance, 2);
            $target = round($entry - ($riskDistance * $rrRatio), 2);
        }

        return [
            'symbol' => $symbol,
            'strategy' => $strategy,
            'signal' => $signal,
            'entry' => round($entry, 2),
            'stop_loss' => $stopLoss,
            'target' => $target,
            'volume_surge' => $volumeSurge,
            'confidence' => $volumeSurge ? 'higher' : 'moderate',
            'reason' => $details . ($volumeSurge ? ', confirmed by a volume surge' : ', no unusual volume yet'),
        ];
    }

    /**
     * Compute ORB (Opening Range Breakout) + VWAP setups.
     */
    public function calculateOrbSetup(array $intradayCandles, array $vwapArray): ?array
    {
        if (count($intradayCandles) < 4) {
            return null;
        }

        $orbHigh = -INF;
        $orbLow = INF;
        $openingCandlesCount = 0;

        $breakoutTriggered = false;
        $breakoutType = null;
        $breakoutEntry = 0.0;
        $breakoutCandleIndex = -1;

        $volumes = array_column($intradayCandles, 'volume');
        $avgVol = count($volumes) > 0 ? array_sum($volumes) / count($volumes) : 0;

        foreach ($intradayCandles as $i => $candle) {
            $date = new \DateTime("@" . $candle['timestamp']);
            $date->setTimezone(new \DateTimeZone('Asia/Kolkata'));
            $timeStr = $date->format('H:i');

            if ($timeStr >= '09:15' && $timeStr <= '09:25') {
                $orbHigh = max($orbHigh, $candle['high']);
                $orbLow = min($orbLow, $candle['low']);
                $openingCandlesCount++;
                continue;
            }

            if ($openingCandlesCount >= 3 && $orbHigh > 0 && $orbLow < INF && !$breakoutTriggered) {
                $close = $candle['close'];
                $vwap = $vwapArray[$i] ?? $close;
                $vol = $candle['volume'];

                if ($close > $orbHigh && $close > $vwap && $vol > $avgVol) {
                    $breakoutTriggered = true;
                    $breakoutType = 'BUY (Long)';
                    $breakoutEntry = $close;
                    $breakoutCandleIndex = $i;
                } elseif ($close < $orbLow && $close < $vwap && $vol > $avgVol) {
                    $breakoutTriggered = true;
                    $breakoutType = 'SELL (Short)';
                    $breakoutEntry = $close;
                    $breakoutCandleIndex = $i;
                }
            }
        }

        if ($breakoutTriggered) {
            $latestCandle = end($intradayCandles);
            $latestPrice = $latestCandle['close'];
            $latestVwap = end($vwapArray);

            $sl = $breakoutType === 'BUY (Long)' ? $orbLow : $orbHigh;
            $risk = abs($breakoutEntry - $sl);
            $tgt = $breakoutType === 'BUY (Long)' ? ($breakoutEntry + $risk * 2) : ($breakoutEntry - $risk * 2);

            $status = 'Active';
            if ($breakoutType === 'BUY (Long)') {
                if ($latestPrice <= $sl) $status = 'Stopped Out (SL Hit) 🔴';
                elseif ($latestPrice <= $latestVwap) $status = 'Trailing Stop Hit (Closed Below VWAP) 🔴';
                elseif ($latestPrice >= $tgt) $status = 'Target 1:2 Hit (Profit Booked) 🟢';
            } else {
                if ($latestPrice >= $sl) $status = 'Stopped Out (SL Hit) 🔴';
                elseif ($latestPrice >= $latestVwap) $status = 'Trailing Stop Hit (Closed Above VWAP) 🔴';
                elseif ($latestPrice <= $tgt) $status = 'Target 1:2 Hit (Profit Booked) 🟢';
            }

            return [
                'orb_high' => round($orbHigh, 2),
                'orb_low' => round($orbLow, 2),
                'entry' => round($breakoutEntry, 2),
                'type' => $breakoutType,
                'stop_loss' => round($sl, 2),
                'target' => round($tgt, 2),
                'trail_sl' => round($latestVwap, 2),
                'status' => $status,
                'reason' => "Price broke range (₹{$orbLow} - ₹{$orbHigh}) at " . date('H:i', $intradayCandles[$breakoutCandleIndex]['timestamp'] + 19800) . " IST, confirmed by VWAP & volume.",
            ];
        }

        return null;
    }
}
