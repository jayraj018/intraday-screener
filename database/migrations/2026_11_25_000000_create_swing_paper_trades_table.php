<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per plan, written before its outcome can be known.
     *
     * Everything above the line is fixed the moment the row is inserted. Only the outcome
     * columns are ever updated, and only once the candles that decide them have closed.
     */
    public function up(): void
    {
        Schema::table('swing_signals', function (Blueprint $table) {
            // The bar the signal came from. scan_date is when the scan ran, which can be days
            // later, and a plan's one-session life is counted from the bar.
            $table->date('signal_date')->nullable();
        });

        Schema::create('swing_paper_trades', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);             // signal | random
            $table->string('symbol', 32);
            $table->string('strategy');
            $table->string('direction', 8);
            $table->date('signal_date');
            $table->timestamp('logged_at');

            // Written down after its entry session had opened, so it may have been chosen by
            // a price it claims to predict. Kept, but never counted.
            $table->boolean('late')->default(false);

            $table->decimal('signal_price', 12, 4);
            $table->decimal('entry_trigger', 12, 4)->nullable();
            $table->decimal('max_entry', 12, 4);
            $table->decimal('stop_price', 12, 4);
            $table->string('stop_method');
            $table->decimal('target1', 12, 4);
            $table->decimal('target2', 12, 4)->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('regime_trend')->nullable();

            // Execution, exit and cost settings on the day. Changing config later must not
            // change how a trade already on the books ends.
            $table->jsonb('rules');

            // Whether the portfolio limits let the paper account take it, and why not
            $table->string('portfolio', 16)->nullable();   // TAKEN | SKIPPED
            $table->string('portfolio_reason')->nullable();
            $table->decimal('planned_value', 14, 2)->default(0);
            $table->decimal('planned_risk', 14, 2)->default(0);

            // ------------------------------------------------------------- outcome
            $table->string('status', 16)->default('PENDING');  // PENDING | OPEN | CLOSED | NO_ENTRY
            $table->decimal('price_factor', 12, 6)->default(1); // split or bonus since the signal
            $table->date('entry_date')->nullable();
            $table->decimal('entry_price', 12, 4)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->date('exit_date')->nullable();
            $table->decimal('exit_price', 12, 4)->nullable();
            $table->string('exit_reason')->nullable();
            $table->decimal('gross_pnl', 14, 2)->nullable();
            $table->decimal('costs', 14, 2)->nullable();
            $table->decimal('net_pnl', 14, 2)->nullable();
            $table->decimal('r_multiple', 10, 4)->nullable();
            $table->unsignedSmallInteger('bars_held')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'symbol', 'strategy', 'signal_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swing_paper_trades');
        Schema::table('swing_signals', fn (Blueprint $table) => $table->dropColumn('signal_date'));
    }
};
