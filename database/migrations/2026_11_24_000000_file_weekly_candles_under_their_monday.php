<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly candles were dated in UTC, so a week starting Monday 00:00 IST was stored under
 * the Sunday before it, and the forming week under whichever day it was downloaded.
 *
 * Finished weeks move to their Monday. Anything left on another day is a snapshot of a
 * week that was still forming, and is dropped: the next scan downloads it complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $weekly = fn () => DB::table('candles')->where('interval', '1wk');

        foreach ($weekly()->distinct()->orderBy('date')->pluck('date') as $date) {
            $day = Carbon::parse($date);

            if ($day->isMonday()) {
                continue;
            }

            if (! $day->isSunday()) {
                $weekly()->where('date', $date)->delete();

                continue;
            }

            $monday = $day->copy()->addDay()->toDateString();

            // Where both exist the Monday row is the half-formed one
            $weekly()->where('date', $monday)
                ->whereIn('symbol', $weekly()->where('date', $date)->pluck('symbol'))
                ->delete();

            $weekly()->where('date', $date)->update(['date' => $monday]);
        }
    }

    public function down(): void
    {
        // The wrong dates are not worth restoring
    }
};
