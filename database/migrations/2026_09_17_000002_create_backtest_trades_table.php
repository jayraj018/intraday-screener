<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every replayed trade, one row each.
     *
     * The backtest used to keep two integers per strategy — trades and wins — and throw
     * the rest away, which made profit factor, expectancy, drawdown, average win/loss and
     * holding period impossible to calculate rather than merely untested. Storing the
     * trade itself is what makes those answerable.
     *
     * `system` is here for the same reason as on backtest_runs: swing trades will land in
     * this table too, and must never be aggregated together with intraday ones.
     */
    public function up(): void
    {
        Schema::create('backtest_trades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('backtest_runs')->cascadeOnDelete();
            $table->string('system')->default('intraday');
            $table->string('symbol');
            $table->string('strategy');
            $table->string('direction'); // BUY | SELL

            // The price that produced the signal, kept alongside the price actually paid
            // so the cost of the delay between them can be measured.
            $table->date('signal_date');
            $table->decimal('signal_price', 12, 4);

            $table->date('entry_date');
            $table->decimal('entry_price', 12, 4);
            $table->date('exit_date');
            $table->decimal('exit_price', 12, 4);
            $table->string('exit_reason'); // stop | target | session_close | vwap_trail

            $table->unsignedInteger('quantity');
            $table->decimal('gross_pnl', 14, 2);
            $table->decimal('costs', 12, 2);
            $table->decimal('net_pnl', 14, 2);

            // Net profit as a multiple of the money risked. Scale-free, so trades on a
            // ₹100 stock and a ₹3,000 one can be averaged together honestly.
            $table->decimal('r_multiple', 10, 4);
            $table->unsignedSmallInteger('bars_held');

            $table->timestamps();

            // Every aggregate is "this run, this strategy, in date order" — the metrics
            // query and the drawdown walk both read exactly this way.
            $table->index(['run_id', 'strategy', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_trades');
    }
};
