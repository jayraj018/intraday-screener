<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per strategy per stock per scan — including the ones that did NOT produce a
     * trade.
     *
     * Storing WATCHLIST and EXTENDED alongside READY is the point of the table. A screener
     * that only records its buys cannot tell you what it nearly bought, or what it passed
     * on because the move had already happened, and those are most of what a swing trader
     * actually needs to look at each morning.
     */
    public function up(): void
    {
        Schema::create('swing_signals', function (Blueprint $table) {
            $table->id();
            $table->date('scan_date');
            $table->string('symbol', 32);
            $table->string('strategy');
            $table->string('state');            // READY | WATCHLIST | EXTENDED | NO_SETUP
            $table->string('direction')->nullable();
            $table->unsignedSmallInteger('score')->nullable();

            // Kept apart on purpose: the price that produced the signal is not the price
            // the trade would be entered at, and conflating them is what let the intraday
            // backtest book overnight gaps as free profit.
            $table->decimal('signal_price', 12, 4);
            $table->decimal('entry_trigger', 12, 4)->nullable();

            $table->decimal('stop_price', 12, 4)->nullable();
            $table->string('stop_method')->nullable();
            $table->decimal('target1', 12, 4)->nullable();
            $table->decimal('target2', 12, 4)->nullable();
            $table->decimal('target1_r', 8, 2)->nullable();
            $table->decimal('target2_r', 8, 2)->nullable();

            $table->string('holding_estimate')->nullable();
            $table->text('watch_for')->nullable();

            $table->jsonb('checks')->nullable();
            $table->jsonb('score_groups')->nullable();

            // The market this was read in, stored with the signal so a past row can still
            // be judged in its own context rather than today's.
            $table->string('regime_trend')->nullable();
            $table->string('regime_volatility')->nullable();
            $table->jsonb('relative_strength')->nullable();

            $table->timestamps();

            $table->unique(['scan_date', 'symbol', 'strategy']);

            // The dashboard query: today's rows, best first, filtered by state
            $table->index(['scan_date', 'state', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_signals');
    }
};
