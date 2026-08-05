<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StockDataService
{
    protected string $baseUrl = 'https://query1.finance.yahoo.com/v8/finance/chart/';

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

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(10)->get($this->baseUrl . $yahooSymbol, [
                'range' => $range,
                'interval' => '1d',
            ]);

            if (! $response->successful()) {
                Log::warning("StockDataService: failed to fetch {$symbol} (HTTP {$response->status()})");

                return null;
            }

            $result = $response->json('chart.result.0');

            if (! $result) {
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
            Log::error("StockDataService: exception fetching {$symbol} — " . $e->getMessage());

            return null;
        }
    }

    /**
     * Fetch 5-minute intraday candles for the current day.
     */
    public function getIntradayCandles(string $symbol): ?array
    {
        $yahooSymbol = strtoupper($symbol) . '.NS';

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ])->timeout(10)->get($this->baseUrl . $yahooSymbol, [
                'range' => '1d',
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
