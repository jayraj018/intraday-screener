<?php

use App\Http\Controllers\ScreenerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ScreenerController::class, 'index'])->name('screener.index');
Route::get('/screener/analyze', [ScreenerController::class, 'analyze'])->name('screener.analyze');
Route::get('/api/screener', [ScreenerController::class, 'api'])->name('screener.api');
Route::get('/api/screener/live', [ScreenerController::class, 'livePrices'])->name('screener.live');
