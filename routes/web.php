<?php

use App\Http\Controllers\BackgroundRunController;
use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ScreenerController;
use App\Http\Controllers\SwingController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ScreenerController::class, 'index'])->name('screener.index');

// Swing is a separate system with its own data, its own backtest and its own costs. It
// shares a database and nothing else, and the two are never aggregated together.
Route::get('/swing', [SwingController::class, 'index'])->name('swing.index');
Route::get('/api/swing', [SwingController::class, 'api'])
    ->middleware('throttle:60,1')
    ->name('swing.api');

// Each of these makes outbound calls to the data provider on every request, so they are
// throttled: without a limit, a handful of open tabs is enough to get the whole app
// rate-limited upstream.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/screener/analyze', [ScreenerController::class, 'analyze'])->name('screener.analyze');
    Route::get('/api/screener/live', [ScreenerController::class, 'livePrices'])->name('screener.live');
});

Route::get('/api/screener', [ScreenerController::class, 'api'])->name('screener.api');

// The last backtest's metrics. Read-only and derived from public market data, so no
// token — but throttled, since the dashboard and any polling both land here.
Route::get('/api/backtest-stats', [BacktestController::class, 'stats'])
    ->middleware('throttle:60,1')
    ->name('backtest.stats');

// Start the daily scan or the backtest in the background (both run far longer than a web
// request may): /api/run-screener?token=...  /api/run-backtest?token=...
Route::get('/api/run-{job}', [BackgroundRunController::class, 'start'])
    ->whereIn('job', ['screener', 'backtest', 'swing'])
    ->middleware('throttle:10,1')
    ->name('background.start');
Route::get('/api/{job}-status', [BackgroundRunController::class, 'status'])
    ->whereIn('job', ['screener', 'backtest', 'swing'])
    ->name('background.status');
