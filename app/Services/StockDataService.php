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
        $yahooSymbol = strtoupper($symbol) . '.NS';
        $this->lastError = null;

        try {
            // Yahoo is CDN-fronted and occasionally serves a cert chain our CA bundle
            // can't verify, or rate-limits a burst. Retrying absorbs those blips so a
            // single unlucky request doesn't silently drop a stock from the scan.
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(10)->retry(3, 300, throw: false)->get($this->baseUrl . $yahooSymbol, [
                'range' => $range,
                'interval' => '1d',
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

            $timestamps = $result['timestamp'] ?? [];
            $quote = $result['indicators']['quote'][0] ?? [];

            $candles = [];

            foreach ($timestamps as $i => $ts) {
                if (! isset($quote['close'][$i]) || $quote['close'][$i] === null) {
                    continue; // skip incomplete/holiday entries
                }

                $candles[] = [
                    'date' => date('Y-m-d', $ts),
                    'open' => $quote['open'][$i],
                    'high' => $quote['high'][$i],
                    'low' => $quote['low'][$i],
                    'close' => $quote['close'][$i],
                    'volume' => $quote['volume'][$i],
                ];
            }

            return $candles;
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
        $yahooSymbol = strtoupper($symbol) . '.NS';

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(10)->retry(3, 300, throw: false)->get($this->baseUrl . $yahooSymbol, [
                'range' => $range,
                'interval' => '5m',
            ]);

            if (! $response->successful()) {
                return null;
            }

            $result = $response->json('chart.result.0');
            if (! $result) {
                return null;
            }

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

            return $candles;
        } catch (\Throwable $e) {
            Log::error("StockDataService: exception fetching intraday {$symbol} — " . $e->getMessage());
            return null;
        }
    }
}
