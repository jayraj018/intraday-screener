<?php

use Illuminate\Support\Facades\Schedule;

// Runs at 9:05 AM IST, Monday–Friday — just before the 9:15 AM market open, so today's
// setups are ready before you need them, and the last daily candle is still yesterday's
// completed one rather than a candle that is mid-session.
//
// --isolated and withoutOverlapping are belt and braces: a scan that overruns must not
// have a second copy started on top of it, and the scan can also be triggered by hand
// through /api/run-screener while the schedule is enabled.
Schedule::command('screener:run --isolated')
    ->weekdays()
    ->timezone('Asia/Kolkata')
    ->at('09:05')
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled screener:run failed'));

// After the close, so the replay covers a completed session. Weekly rather than daily:
// a 2-year replay across the Nifty 500 takes far too long to run every night, and the
// win rates it produces barely move from one day to the next.
Schedule::command('screener:backtest --isolated')
    ->saturdays()
    ->timezone('Asia/Kolkata')
    ->at('06:00')
    ->withoutOverlapping()
    ->onFailure(fn () => logger()->error('Scheduled screener:backtest failed'));
