<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a strategy's trades ended, as {"stop": 8, "target": 0, "session_close": 264}.
     *
     * Stored on the stats row rather than counted from backtest_trades on demand: the
     * dashboard renders this on every page load, and a run holds around a hundred
     * thousand trades. It only changes when a backtest runs, so it is written once there.
     */
    public function up(): void
    {
        Schema::table('strategy_stats', function (Blueprint $table) {
            $table->jsonb('exit_breakdown')->nullable()->after('worst_trade_r');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_stats', function (Blueprint $table) {
            $table->dropColumn('exit_breakdown');
        });
    }
};
