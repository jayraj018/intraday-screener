<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StockDataService
{
    protected string $baseUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    /** Why the last fetch returned null: 'not_found', 'network', 'no_data', or null on success. */
    protected ?string $lastError = null;

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Fetch daily OHLCV candles for an NSE symbol.
     * Pass symbol as e.g. "RELIANCE" — .NS is appended automatically for Yahoo Finance.
     *
     * Note: this free endpoint is unofficial and can change or rate-limit without
     * notice. For production reliability, swap this service for a paid data
     * provider (Zerodha Kite Connect, Upstox, Alpha Vantage) later — the rest of
     * the app doesn't need to change, since everything depends on this one class.
     */
    public function getDailyCandles(string $symbol, string $range = '3mo'): ?array
    {
        $result = $this->fetch($symbol, $range, '1d');

        if (! $result) {
            return null;
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $adjClose = $result['indicators']['adjclose'][0]['adjclose'] ?? [];

        $candles = [];

        foreach ($timestamps as $i => $ts) {
                if (! isset($quote['close'][$i]) || $quote['close'][$i] === null) {
                    continue; // skip incomplete/holiday entries
                }

                // Yahoo fills NSE market holidays with a placeholder bar: the previous
                // close repeated as open/high/low/close, and zero volume. About 1.2% of
                // daily bars. They are not trading days, and leaving them in drags the
                // 20-day average volume down (making volume surges easier to trigger) and
                // ATR down (making stops tighter), on top of letting the backtest "trade"
                // a day the market was shut.
                if (($quote['volume'][$i] ?? 0) == 0) {
                    continue;
                }

            $candles[] = [
                'date' => gmdate('Y-m-d', $ts),
                'open' => $quote['open'][$i],
                'high' => $quote['high'][$i],
                'low' => $quote['low'][$i],
                'close' => $quote['close'][$i],
                'adj_close' => $adjClose[$i] ?? null,
                'volume' => $quote['volume'][$i],
            ];
        }

        return $candles;
    }

    /**
     * Weekly candles, for the broader trend context a swing setup is judged against.
     * Same shape and same cleaning as the daily ones.
     */
    public function getWeeklyCandles(string $symbol, string $range = '2y'): ?array
    {
        $result = $this->fetch($symbol, $range, '1wk');

        if (! $result) {
            return null;
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $adjClose = $result['indicators']['adjclose'][0]['adjclose'] ?? [];

        $candles = [];

        foreach ($timestamps as $i => $ts) {
            if (! isset($quote['close'][$i]) || $quote['close'][$i] === null || ($quote['volume'][$i] ?? 0) == 0) {
                continue;
            }

            $candles[] = [
                'date' => gmdate('Y-m-d', $ts),
                'open' => $quote['open'][$i],
                'high' => $quote['high'][$i],
                'low' => $quote['low'][$i],
                'close' => $quote['close'][$i],
                'adj_close' => $adjClose[$i] ?? null,
                'volume' => $quote['volume'][$i],
            ];
        }

        return $candles;
    }

    /**
     * One request to the provider, returning the raw chart result or null.
     *
     * A symbol beginning with "^" is an index (^NSEI is NIFTY 50) and takes no exchange
     * suffix — appending ".NS" to it returns a 404, which is why the benchmark could not
     * be fetched at all before.
     */
    protected function fetch(string $symbol, string $range, string $interval): ?array
    {
        $symbol = strtoupper($symbol);
        $yahooSymbol = str_starts_with($symbol, '^') ? $symbol : $symbol . '.NS';
        $this->lastError = null;

        try {
            // Yahoo is CDN-fronted and occasionally serves a cert chain our CA bundle
            // can't verify, or rate-limits a burst. Retrying absorbs those blips so a
            // single unlucky request doesn't silently drop a stock from the scan.
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(10)->retry(3, 300, throw: false)->get($this->baseUrl . rawurlencode($yahooSymbol), [
                'range' => $range,
                'interval' => $interval,
            ]);

            if ($response->status() === 404) {
                $this->lastError = 'not_found';
                Log::warning("StockDataService: symbol {$symbol} not listed on NSE (HTTP 404)");

                return null;
            }

            if (! $response->successful()) {
                $this->lastError = 'network';
                Log::warning("StockDataService: failed to fetch {$symbol} (HTTP {$response->status()})");

                return null;
            }

            $result = $response->json('chart.result.0');

            if (! $result) {
                $this->lastError = 'no_data';
                Log::warning("StockDataService: no data returned for {$symbol}");

                return null;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->lastError = 'network';
            Log::error("StockDataService: exception fetching {$symbol} — " . $e->getMessage());

            return null;
        }
    }

    /**
     * Fetch 5-minute intraday candles — the current day by default. Yahoo keeps
     * 5-minute history for at most the last 60 days.
     */
    public function getIntradayCandles(string $symbol, string $range = '1d'): ?array
    {
        $result = $this->fetch($symbol, $range, '5m');

        if (! $result) {
            return null;
        }

        {
            $timestamps = $result['timestamp'] ?? [];
            $quote = $result['indicators']['quote'][0] ?? [];

            $candles = [];
            foreach ($timestamps as $i => $ts) {
                if (! isset($quote['close'][$i]) || $quote['close'][$i] === null) {
                    continue;
                }

                $candles[] = [
                    'timestamp' => $ts,
                    'open' => $quote['open'][$i],
                    'high' => $quote['high'][$i],
                    'low' => $quote['low'][$i],
                    'close' => $quote['close'][$i],
                    'volume' => $quote['volume'][$i] ?? 0,
                ];
            }

            // Mid-session Yahoo appends a stub for the candle still being built: zero
            // volume, and open/high/low/close all the same last traded price. Reading it
            // as a real candle reported "volume 0" against a 130K average and priced
            // setups off a bar that hadn't traded. Only trailing stubs are dropped — an
            // illiquid stock can genuinely print no volume in the middle of a session.
            while ($candles && end($candles)['volume'] == 0) {
                array_pop($candles);
            }

            return $candles;
        }
    }
}
