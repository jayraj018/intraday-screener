<?php

use App\Http\Controllers\BackgroundRunController;
use App\Http\Controllers\ScreenerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ScreenerController::class, 'index'])->name('screener.index');
Route::get('/screener/analyze', [ScreenerController::class, 'analyze'])->name('screener.analyze');
Route::get('/api/screener', [ScreenerController::class, 'api'])->name('screener.api');
Route::get('/api/screener/live', [ScreenerController::class, 'livePrices'])->name('screener.live');

// Start the daily scan or the backtest in the background (both run far longer than a web
// request may): /api/run-screener?token=...  /api/run-backtest?token=...
Route::get('/api/run-{job}', [BackgroundRunController::class, 'start'])
    ->whereIn('job', ['screener', 'backtest'])
    ->name('background.start');
Route::get('/api/{job}-status', [BackgroundRunController::class, 'status'])
    ->whereIn('job', ['screener', 'backtest'])
    ->name('background.status');
