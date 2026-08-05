<?php

use Illuminate\Support\Facades\Schedule;

// Runs at 9:05 AM IST, Monday–Friday — just before the 9:15 AM market open,
// so today's setups are ready before you need them.
Schedule::command('screener:run')
    ->weekdays()
    ->timezone('Asia/Kolkata')
    ->at('09:05');
