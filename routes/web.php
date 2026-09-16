<?php

use App\Http\Controllers\BacktestController;
use App\Http\Controllers\ScreenerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ScreenerController::class, 'index'])->name('screener.index');
Route::get('/screener/analyze', [ScreenerController::class, 'analyze'])->name('screener.analyze');
Route::get('/api/screener', [ScreenerController::class, 'api'])->name('screener.api');
Route::get('/api/screener/live', [ScreenerController::class, 'livePrices'])->name('screener.live');

// Starts the backtest in the background (it runs far longer than a web request may): /api/run-backtest?token=...
Route::get('/api/run-backtest', [BacktestController::class, 'start'])->name('backtest.start');
Route::get('/api/backtest-status', [BacktestController::class, 'status'])->name('backtest.status');
