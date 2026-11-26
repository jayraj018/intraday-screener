<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Official NSE end-of-day bars (sec_bhavdata_full), which carry what Yahoo does not:
     * delivery quantity and delivery %. And the research pattern's signals, logged before their
     * outcome is known and then resolved by the same rules the research backtest used.
     *
     * Both are additive. Nothing in the existing swing or intraday flow reads them.
     */
    public function up(): void
    {
        Schema::create('nse_daily_bars', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('symbol', 32);
            $table->string('series', 4);
            $table->decimal('prev_close', 14, 4)->nullable();
            $table->decimal('open', 14, 4);
            $table->decimal('high', 14, 4);
            $table->decimal('low', 14, 4);
            $table->decimal('close', 14, 4);
            $table->unsignedBigInteger('volume');
            $table->decimal('turnover', 20, 2);            // rupees
            $table->unsignedBigInteger('deliv_qty')->nullable();
            $table->decimal('deliv_pct', 6, 2)->nullable();
            $table->unique(['symbol', 'date']);
            $table->index('date');
        });

        Schema::create('research_signals', function (Blueprint $table) {
            $table->id();
            $table->string('pattern', 64);
            $table->string('symbol', 32);
            $table->date('signal_date');
            $table->timestamp('logged_at');
            $table->boolean('late')->default(false);       // logged after the entry session opened: never counted
            $table->decimal('signal_close', 14, 4);
            $table->decimal('atr', 14, 4);
            $table->decimal('stop_price', 14, 4);
            $table->decimal('reference_target', 14, 4);     // 2R, display only: the rule exits on a trailing stop
            $table->jsonb('conditions');
            $table->jsonb('rules');
            $table->string('status', 16)->default('PENDING'); // PENDING | OPEN | CLOSED | NO_ENTRY
            $table->string('no_entry_reason')->nullable();
            $table->date('entry_date')->nullable();
            $table->decimal('entry_price', 14, 4)->nullable();
            $table->decimal('current_stop', 14, 4)->nullable();
            $table->decimal('highest_close', 14, 4)->nullable();
            $table->unsignedSmallInteger('bars_held')->default(0);
            $table->date('exit_date')->nullable();
            $table->decimal('exit_price', 14, 4)->nullable();
            $table->string('exit_reason', 24)->nullable();
            $table->decimal('net_pct', 10, 6)->nullable();
            $table->decimal('r_multiple', 10, 4)->nullable();
            $table->timestamps();
            $table->unique(['pattern', 'symbol', 'signal_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_signals');
        Schema::dropIfExists('nse_daily_bars');
    }
};
