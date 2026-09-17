<?php

namespace App\Services;

class ScreenerService
{
    /** Length of one intraday candle, used to tell a finished bar from the one still filling. */
    protected const INTRADAY_SECONDS = 300;

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
    public function run(?callable $onEachSymbol = null): array
    {
        $slMultiplier = config('screener.stop_loss_atr_multiplier');
        $rrRatio = config('screener.risk_reward_ratio');

        $results = [];

        foreach ($this->watchlist() as $symbol) {
            // Called before any skip below, so progress counts every stock
            if ($onEachSymbol) {
                $onEachSymbol($symbol);
            }

            $candles = $this->completedDailyCandles($this->dataService->getDailyCandles($symbol) ?? []);
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
                $orbSetup = $this->liveOrbSetup($intradayCandles, $vwapArray);

                // A breakout confirmed on the newest candle has no entry price yet — it
                // is taken at the next candle's open, so there is nothing to save.
                if ($orbSetup && $orbSetup['entry'] !== null) {
                    $results[] = [
                        'symbol' => $symbol,
                        'strategy' => 'ORB + VWAP Breakout',
                        'signal' => $orbSetup['type'] === 'BUY (Long)' ? 'BUY' : 'SELL (short)',
                        'entry' => $orbSetup['entry'],
                        'stop_loss' => $orbSetup['stop_loss'],
                        'target' => $orbSetup['target'],
                        'volume_surge' => $volumeSurge,
                        'confidence' => $volumeSurge ? 'higher' : 'moderate',
                        'reason' => $orbSetup['reason'] . ' Entered at ₹' . number_format($orbSetup['entry'], 2) . ' on the ' . $orbSetup['entry_at'] . ' open.',
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
    public function dailyContext(array $candles, bool $requireLiquidity = true): ?array
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

        // The liquidity floor decides which stocks are worth *scanning*; it is not part of
        // any strategy rule. Someone who searches a specific stock has already chosen it,
        // so the search page asks for the same context without this filter — the setups
        // that come out are identical either way.
        if ($requireLiquidity && $avgVolume < config('screener.min_avg_volume')) {
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

    /**
     * A read of where each indicator stands right now, for the search page's technical
     * breakdown — plus an overall bias from how many lean each way.
     *
     * This is a different question from dailySetups(). A setup fires on an *event*: the
     * moment a line crosses another. This reports a *state*: which side of the line price
     * is on today. A stock can be solidly bullish on every indicator and produce no setup
     * at all, because nothing crossed.
     *
     * It lives here, beside the strategies, so both read the same periods from the same
     * config. They used to be computed in the controller with 9 and 21 hardcoded, which
     * meant changing config/screener.php moved the screener and left this panel behind.
     */
    public function technicalHealth(array $candles): ?array
    {
        if (count($candles) < 50) {
            return null;
        }

        $closes = array_column($candles, 'close');
        $closesPrev = array_slice($closes, 0, -1);
        $latest = end($candles);
        $closeNow = $latest['close'];
        $closePrev = $candles[count($candles) - 2]['close'];

        $bullish = 0;
        $bearish = 0;
        $indicators = [];

        // 1. Moving averages — which side of the trend price is on
        $shortMa = $this->indicators->sma($closes, $short = config('screener.short_ma'));
        $longMa = $this->indicators->sma($closes, $long = config('screener.long_ma'));
        $maUp = $shortMa && $longMa && $shortMa > $longMa;

        $maUp ? $bullish++ : ($shortMa && $longMa ? $bearish++ : null);

        $indicators['ma'] = [
            'status' => $shortMa && $longMa ? ($maUp ? 'Bullish' : 'Bearish') : 'Neutral',
            'detail' => $shortMa && $longMa
                ? "{$short} SMA is trending " . ($maUp ? 'above' : 'below') . " {$long} SMA"
                : 'Not enough history for the moving averages',
            'short_val' => round((float) $shortMa, 2),
            'long_val' => round((float) $longMa, 2),
        ];

        // 2. RSI — an extreme is a reversal watch, not a signal on its own
        $rsi = $this->indicators->rsi($closes);
        $rsiPrev = $this->indicators->rsi($closesPrev);

        $indicators['rsi'] = match (true) {
            $rsi === null => ['status' => 'Neutral', 'detail' => 'Not enough history for RSI', 'value' => 0.0],
            $rsi < 30 => ['status' => 'Oversold / Reversal Watch', 'detail' => 'RSI is deeply oversold (< 30). Watch for a pullback', 'value' => round($rsi, 2)],
            $rsi > 70 => ['status' => 'Overbought / Reversal Watch', 'detail' => 'RSI is overbought (> 70). Risk of a correction', 'value' => round($rsi, 2)],
            default => ['status' => 'Neutral', 'detail' => 'RSI is neutral and ' . ($rsiPrev !== null && $rsi > $rsiPrev ? 'rising' : 'falling'), 'value' => round($rsi, 2)],
        };

        if ($rsi !== null && $rsi < 30) {
            $bullish++; // oversold reads as a bullish reversal watch
        } elseif ($rsi !== null && $rsi > 70) {
            $bearish++;
        }

        // 3. Bollinger Bands — inside the bands is ordinary, outside is not
        $bands = $this->indicators->bollingerBands($closes);
        $aboveBand = $bands && $closeNow > $bands['upper'];
        $belowBand = $bands && $closeNow < $bands['lower'];

        $aboveBand and $bullish++;
        $belowBand and $bearish++;

        $indicators['bb'] = [
            'status' => match (true) {
                $aboveBand => 'Bullish Breakout',
                $belowBand => 'Bearish Breakdown',
                default => 'Neutral',
            },
            'detail' => match (true) {
                $aboveBand => 'Price closed outside the Upper Bollinger Band',
                $belowBand => 'Price closed outside the Lower Bollinger Band',
                default => 'Price is inside standard volatility bands',
            },
            'upper' => round((float) ($bands['upper'] ?? 0), 2),
            'middle' => round((float) ($bands['middle'] ?? 0), 2),
            'lower' => round((float) ($bands['lower'] ?? 0), 2),
        ];

        // 4. MACD — momentum relative to its own signal line
        $macd = $this->indicators->macd($closes);
        $macdUp = $macd && $macd['macd_now'] > $macd['signal_now'];

        $macd ? ($macdUp ? $bullish++ : $bearish++) : null;

        $indicators['macd'] = [
            'status' => $macd ? ($macdUp ? 'Bullish' : 'Bearish') : 'Neutral',
            'detail' => $macd
                ? 'MACD line is ' . ($macdUp ? 'above' : 'below') . ' the signal line'
                : 'Not enough history for MACD',
            'macd' => round($macd['macd_now'] ?? 0, 2),
            'signal' => round($macd['signal_now'] ?? 0, 2),
        ];

        // 5. Volume — is the move being backed?
        $avgVolume = $this->indicators->averageVolume($candles);
        $surge = $avgVolume && $latest['volume'] >= $avgVolume * config('screener.volume_surge_multiplier');

        if ($surge) {
            $closeNow > $closePrev ? $bullish++ : $bearish++;
        }

        $indicators['volume'] = [
            'status' => $surge ? 'Volume Surge' : 'Normal',
            'detail' => $surge
                ? 'Trading volume is ' . round($latest['volume'] / $avgVolume, 1) . 'x above average'
                : 'Volume is in the normal average range',
            'current' => $latest['volume'],
            'avg' => round((float) $avgVolume),
        ];

        return [
            'indicators' => $indicators,
            'bullish' => $bullish,
            'bearish' => $bearish,
            'bias' => match (true) {
                $bullish >= 3 => 'BULLISH',
                $bearish >= 3 => 'BEARISH',
                default => 'NEUTRAL',
            },
        ];
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
     * Daily candles with today's dropped while the session is still running.
     *
     * Yahoo returns a row for the current day from the opening bell, with the live price
     * as its "close" and only the volume traded so far. Every daily strategy read that as
     * a finished candle, so a setup's entry price changed on every refresh, a volume rule
     * could cross its threshold mid-morning, and "price closed positive" was reported at
     * 10:41. The backtest has always dropped this candle — this is the live scan catching
     * up with it.
     *
     * After the close the candle is final and is kept, so an evening scan still produces
     * today's signals for tomorrow.
     */
    public function completedDailyCandles(array $candles): array
    {
        if (! $candles) {
            return [];
        }

        $now = now('Asia/Kolkata');
        $isToday = end($candles)['date'] === $now->toDateString();

        if ($isToday && $now->format('H:i') < config('screener.market_close')) {
            array_pop($candles);
        }

        return $candles;
    }

    /**
     * The most recent trading session's candles out of a multi-day intraday fetch.
     *
     * Anything reading a single session — an opening range, a day's VWAP — needs this,
     * because a multi-day array would fold several 09:15 opens into one range.
     */
    public function latestSession(array $intradayCandles): array
    {
        $sessions = [];

        foreach ($intradayCandles as $candle) {
            $sessions[gmdate('Y-m-d', $candle['timestamp'] + 19800)][] = $candle; // IST date
        }

        return $sessions ? end($sessions) : [];
    }

    /**
     * Whether this stock can be entered right now, at the price on screen.
     *
     * Every other setup on the page reports what the rules did earlier in the session,
     * so the entry price they show has already gone. This one measures against the
     * latest candle, so the entry is a price still available — and answers WAIT rather
     * than inventing a level when nothing is tradeable.
     *
     * Rule-based only: it reports whether the conditions are met, never whether the
     * trade will work out.
     */
    public function liveTradeDecision(array $intradayCandles): ?array
    {
        $limits = config('screener.live');
        $rrRatio = config('screener.risk_reward_ratio');

        $latest = end($intradayCandles);

        if (! $latest) {
            return null;
        }

        $price = $latest['close'];

        // The newest candle is still filling, so only a fraction of its volume has
        // printed. Measuring that against a full-bar average makes every stock look
        // like it has no volume, so the volume test uses the last complete bar while
        // the price stays live.
        $complete = $intradayCandles;

        if (end($complete)['timestamp'] + self::INTRADAY_SECONDS > time()) {
            array_pop($complete);
        }

        // VWAP restarts each session; the 20 EMA does not, so it is computed over
        // every candle supplied while VWAP sees only today's.
        $today = $this->latestSession($intradayCandles);

        $vwapArray = $this->indicators->vwap($today);
        $emaArray = $this->indicators->emaArray(array_column($intradayCandles, 'close'), 20);
        $avgVolume = $this->indicators->averageVolume($complete, 20);
        $lastComplete = end($complete);

        if (! $vwapArray || ! $emaArray || ! $avgVolume || ! $lastComplete) {
            return null;
        }

        $vwap = end($vwapArray);
        $ema20 = end($emaArray);
        $relativeVolume = $lastComplete['volume'] / $avgVolume;

        $now = now('Asia/Kolkata');
        $sessionOver = gmdate('Y-m-d', $latest['timestamp'] + 19800) !== $now->toDateString();
        $tooLate = ! $sessionOver && $now->format('H:i') >= $limits['no_new_entry_after'];

        // Both levels on the same side of price, or there is no side to take
        $direction = match (true) {
            $price > $vwap && $price > $ema20 => 'BUY',
            $price < $vwap && $price < $ema20 => 'SELL',
            default => null,
        };

        $stop = $target = $risk = $riskPercent = null;

        if ($direction) {
            // The stop goes beyond *both* levels, so a wick through the nearer one
            // doesn't close the trade
            $stop = $direction === 'BUY'
                ? min($vwap, $ema20) * (1 - $limits['stop_buffer'])
                : max($vwap, $ema20) * (1 + $limits['stop_buffer']);

            $risk = abs($price - $stop);
            $riskPercent = $risk / $price * 100;
            $target = $direction === 'BUY' ? $price + $risk * $rrRatio : $price - $risk * $rrRatio;
        }

        $blockers = [];

        if ($sessionOver) {
            $blockers[] = 'the market is closed — these are the last session\'s candles';
        } elseif ($tooLate) {
            $blockers[] = 'too late in the session to open a new intraday trade (cut-off ' . $limits['no_new_entry_after'] . ')';
        }

        if (! $direction) {
            $blockers[] = 'price is between VWAP and the 20 EMA, so there is no clear side to take';
        } elseif ($riskPercent > $limits['max_risk_percent']) {
            $blockers[] = 'price has run ' . round($riskPercent, 2) . '% above its support — too far to enter safely';
        }

        if ($relativeVolume < $limits['min_relative_volume']) {
            $blockers[] = 'volume is below average, so the move isn\'t being backed';
        }

        $action = $blockers ? 'WAIT' : $direction;

        return [
            'as_of' => gmdate('H:i', $latest['timestamp'] + 19800),
            'price' => round($price, 2),
            'action' => $action,
            'direction' => $direction,
            'checks' => [
                [
                    'label' => 'Price vs VWAP',
                    'detail' => '₹' . number_format($price, 2) . ($price > $vwap ? ' above ' : ' below ') . '₹' . number_format($vwap, 2),
                    'pass' => $direction !== null,
                ],
                [
                    'label' => 'Price vs 20 EMA',
                    'detail' => '₹' . number_format($price, 2) . ($price > $ema20 ? ' above ' : ' below ') . '₹' . number_format($ema20, 2),
                    'pass' => $direction !== null,
                ],
                [
                    'label' => 'Volume',
                    'detail' => 'last full 5m bar ' . number_format($lastComplete['volume']) . ' vs ' . number_format($avgVolume) . ' average (' . round($relativeVolume, 2) . 'x)',
                    'pass' => $relativeVolume >= $limits['min_relative_volume'],
                ],
                [
                    'label' => 'Risk to support',
                    'detail' => $riskPercent === null
                        ? 'no side to measure from'
                        : round($riskPercent, 2) . '% (limit ' . $limits['max_risk_percent'] . '%)',
                    'pass' => $riskPercent !== null && $riskPercent <= $limits['max_risk_percent'],
                ],
                [
                    'label' => 'Session time',
                    'detail' => $sessionOver ? 'market closed' : ($tooLate ? 'past the ' . $limits['no_new_entry_after'] . ' cut-off' : 'open until ' . $limits['square_off_at']),
                    'pass' => ! $sessionOver && ! $tooLate,
                ],
            ],
            'blockers' => $blockers,
            'watch' => $this->liveTradeWatch($action, $direction, $vwap, $ema20, $riskPercent, $limits),
            'entry' => $action === 'WAIT' ? null : round($price, 2),
            'stop_loss' => $action === 'WAIT' ? null : round($stop, 2),
            'target' => $action === 'WAIT' ? null : round($target, 2),
            'risk' => $action === 'WAIT' ? null : round($risk, 2),
            'risk_percent' => $action === 'WAIT' ? null : round($riskPercent, 2),
            'reward' => $action === 'WAIT' ? null : round($risk * $rrRatio, 2),
            'reward_percent' => $action === 'WAIT' ? null : round($risk * $rrRatio / $price * 100, 2),
            'rr_ratio' => $rrRatio,
            'square_off_at' => $limits['square_off_at'],
        ];
    }

    /**
     * What has to happen before a waiting stock becomes tradeable, in the same terms
     * the decision itself uses — so "WAIT" is an instruction rather than a dead end.
     */
    protected function liveTradeWatch(string $action, ?string $direction, float $vwap, float $ema20, ?float $riskPercent, array $limits): ?string
    {
        if ($action !== 'WAIT') {
            return null;
        }

        if (! $direction) {
            return 'A long needs a close above ₹' . number_format(max($vwap, $ema20), 2)
                . '; a short needs a close below ₹' . number_format(min($vwap, $ema20), 2) . '.';
        }

        if ($riskPercent !== null && $riskPercent > $limits['max_risk_percent']) {
            // The price at which the stop would sit exactly on the risk limit
            $level = $direction === 'BUY'
                ? min($vwap, $ema20) * (1 - $limits['stop_buffer']) / (1 - $limits['max_risk_percent'] / 100)
                : max($vwap, $ema20) * (1 + $limits['stop_buffer']) / (1 + $limits['max_risk_percent'] / 100);

            return 'Wait for a pullback to about ₹' . number_format($level, 2)
                . '. Entering there keeps the risk inside ' . $limits['max_risk_percent'] . '%.';
        }

        return 'Wait for volume to come in behind the move.';
    }

    /**
     * The first opening-range breakout in the candles supplied, or null if there isn't
     * one yet. Detection only — see fillOrbSetup() for the price the trade is taken at.
     *
     * Only the candles passed in are used, so replaying a session one candle at a time
     * sees exactly what the live scan sees at that moment. That is why the volume
     * threshold averages the candles *before* the one being tested instead of the whole
     * array: averaging the whole session let a volume spike from later in the day veto
     * an earlier, genuine breakout, so the live dashboard reported a breakout candle
     * that could only have been picked with hindsight — and one the backtest, which
     * only ever sees a prefix, would never have picked.
     */
    public function calculateOrbSetup(array $intradayCandles, array $vwapArray): ?array
    {
        if (count($intradayCandles) < 4) {
            return null;
        }

        $orbHigh = -INF;
        $orbLow = INF;
        $openingCandles = 0;
        $volumeSoFar = 0.0;

        foreach ($intradayCandles as $i => $candle) {
            // The session's average volume as it stood before this candle printed
            $avgVol = $i > 0 ? $volumeSoFar / $i : 0.0;
            $volumeSoFar += $candle['volume'];

            $date = new \DateTime('@' . $candle['timestamp']);
            $date->setTimezone(new \DateTimeZone('Asia/Kolkata'));
            $timeStr = $date->format('H:i');

            if ($timeStr >= '09:15' && $timeStr <= '09:25') {
                $orbHigh = max($orbHigh, $candle['high']);
                $orbLow = min($orbLow, $candle['low']);
                $openingCandles++;
                continue;
            }

            if ($openingCandles < 3 || $orbHigh <= 0 || $orbLow >= INF) {
                continue;
            }

            $close = $candle['close'];
            $vwap = $vwapArray[$i] ?? $close;

            if ($candle['volume'] <= $avgVol) {
                continue;
            }

            if ($close > $orbHigh && $close > $vwap) {
                $type = 'BUY (Long)';
            } elseif ($close < $orbLow && $close < $vwap) {
                $type = 'SELL (Short)';
            } else {
                continue;
            }

            $confirmedAt = gmdate('H:i', $candle['timestamp'] + 19800); // IST

            return [
                'type' => $type,
                'orb_high' => round($orbHigh, 2),
                'orb_low' => round($orbLow, 2),
                'breakout_index' => $i,
                'confirm_price' => round($close, 2),
                'confirmed_at' => $confirmedAt,
                'reason' => 'Price closed outside the opening range (₹' . number_format($orbLow, 2) . ' - ₹' . number_format($orbHigh, 2) . ') at '
                    . $confirmedAt . ' IST, confirmed by VWAP & volume.',
            ];
        }

        return null;
    }

    /**
     * Turn a detected breakout into a tradeable setup.
     *
     * The breakout is only confirmed once its candle has closed — neither the closing
     * price, nor VWAP, nor the volume comparison exist before that — so the first price
     * actually available to trade on is the open of the following candle. Filling at
     * the breakout level or at the confirming close would book a price that existed
     * before the signal did.
     *
     * Null when the confirming candle is the last one there is: nothing to enter on yet.
     */
    public function fillOrbSetup(array $setup, ?array $entryCandle): ?array
    {
        if (! $entryCandle) {
            return null;
        }

        $isBuy = $setup['type'] === 'BUY (Long)';
        $entry = $entryCandle['open'];
        $stopLoss = $isBuy ? $setup['orb_low'] : $setup['orb_high'];
        $risk = abs($entry - $stopLoss);

        // Price opened straight through the far side of the range — no risk to size against
        if ($risk <= 0) {
            return null;
        }

        return [
            ...$setup,
            'entry' => round($entry, 2),
            'entry_at' => gmdate('H:i', $entryCandle['timestamp'] + 19800), // IST
            'stop_loss' => round($stopLoss, 2),
            'target' => round($isBuy ? $entry + $risk * 2 : $entry - $risk * 2, 2),
            'risk' => round($risk, 2),
            'risk_percent' => round($risk / $entry * 100, 2),
        ];
    }

    /**
     * Where a filled setup stands, walking every candle from the entry onwards.
     *
     * The card used to compare only the *latest* price against the levels, so a trade
     * that had already been stopped out or trailed out went back to showing "Active"
     * as soon as price came back through the level.
     *
     * @param array $after     Candles from the entry candle onwards
     * @param array $afterVwap VWAP over the same candles
     */
    public function orbStatus(array $filled, array $after, array $afterVwap): array
    {
        $isBuy = $filled['type'] === 'BUY (Long)';
        $entry = $filled['entry'];

        $closed = fn (string $status, float $exit, int $timestamp) => [
            'status' => $status,
            'is_open' => false,
            'exit' => round($exit, 2),
            'exit_at' => gmdate('H:i', $timestamp + 19800),
        ] + $this->orbPnl($isBuy, $entry, $exit);

        foreach ($after as $i => $candle) {
            // A 5-minute candle doesn't show which level was touched first, so a candle
            // reaching both counts as the stop — the same rule the backtest uses.
            if ($isBuy ? $candle['low'] <= $filled['stop_loss'] : $candle['high'] >= $filled['stop_loss']) {
                return $closed('Stopped Out (SL Hit) 🔴', $filled['stop_loss'], $candle['timestamp']);
            }

            if ($isBuy ? $candle['high'] >= $filled['target'] : $candle['low'] <= $filled['target']) {
                return $closed('Target 1:2 Hit 🟢', $filled['target'], $candle['timestamp']);
            }

            $vwap = $afterVwap[$i] ?? null;

            if ($vwap !== null && ($isBuy ? $candle['close'] <= $vwap : $candle['close'] >= $vwap)) {
                $inProfit = $isBuy ? $candle['close'] > $entry : $candle['close'] < $entry;

                return $closed('Trailing Stop Hit (VWAP) ' . ($inProfit ? '🟢' : '🔴'), $candle['close'], $candle['timestamp']);
            }
        }

        $last = $after ? end($after) : null;

        return [
            'status' => 'Active',
            'is_open' => true,
            'exit' => null,
            'exit_at' => null,
        ] + $this->orbPnl($isBuy, $entry, $last['close'] ?? $entry);
    }

    protected function orbPnl(bool $isBuy, float $entry, float $price): array
    {
        $pnl = $isBuy ? $price - $entry : $entry - $price;

        return [
            'pnl' => round($pnl, 2),
            'pnl_percent' => $entry > 0 ? round($pnl / $entry * 100, 2) : 0.0,
        ];
    }

    /**
     * The ORB setup for a session's candles so far: detection, the fill that follows
     * it, and where the trade stands now. Used by everything that displays live data.
     *
     * A setup whose breakout candle is the newest one comes back with a null entry —
     * the breakout is confirmed but the price to enter at hasn't printed yet.
     */
    public function liveOrbSetup(array $intradayCandles, array $vwapArray): ?array
    {
        $setup = $this->calculateOrbSetup($intradayCandles, $vwapArray);

        if (! $setup) {
            return null;
        }

        $next = $setup['breakout_index'] + 1;
        $trailSl = $vwapArray ? round(end($vwapArray), 2) : null;
        $filled = $this->fillOrbSetup($setup, $intradayCandles[$next] ?? null);

        if (! $filled) {
            return [
                ...$setup,
                'entry' => null,
                'entry_at' => null,
                'stop_loss' => null,
                'target' => null,
                'risk' => null,
                'risk_percent' => null,
                'status' => 'Awaiting entry at the next 5m open',
                'is_open' => true,
                'exit' => null,
                'exit_at' => null,
                'pnl' => null,
                'pnl_percent' => null,
                'trail_sl' => $trailSl,
            ];
        }

        return [
            ...$filled,
            ...$this->orbStatus($filled, array_slice($intradayCandles, $next), array_slice($vwapArray, $next)),
            'trail_sl' => $trailSl,
        ];
    }
}
