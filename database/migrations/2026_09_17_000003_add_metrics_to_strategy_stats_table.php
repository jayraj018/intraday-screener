<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Win rate alone can't tell you whether a strategy makes money — 45% wins with large
     * winners beats 65% wins with larger losers. These are the numbers that can.
     *
     * Everything is in R (multiples of the money risked) so strategies on cheap and
     * expensive stocks aggregate honestly. Nullable because a strategy that produced no
     * losing trades has no average loss and no profit factor, and inventing one would be
     * worse than showing nothing.
     */
    public function up(): void
    {
        Schema::table('strategy_stats', function (Blueprint $table) {
            $table->foreignId('run_id')->nullable()->after('id')->constrained('backtest_runs')->nullOnDelete();
            $table->decimal('avg_win_r', 10, 4)->nullable()->after('win_rate');
            $table->decimal('avg_loss_r', 10, 4)->nullable()->after('avg_win_r');
            $table->decimal('profit_factor', 10, 4)->nullable()->after('avg_loss_r');
            $table->decimal('expectancy_r', 10, 4)->nullable()->after('profit_factor');
            $table->decimal('max_drawdown_r', 10, 4)->nullable()->after('expectancy_r');
            $table->decimal('total_return_r', 12, 4)->nullable()->after('max_drawdown_r');
            $table->decimal('net_pnl', 14, 2)->nullable()->after('total_return_r');
            $table->decimal('avg_bars_held', 8, 2)->nullable()->after('net_pnl');
            $table->unsignedSmallInteger('max_consecutive_losses')->nullable()->after('avg_bars_held');
            $table->decimal('best_trade_r', 10, 4)->nullable()->after('max_consecutive_losses');
            $table->decimal('worst_trade_r', 10, 4)->nullable()->after('best_trade_r');
        });
    }

    public function down(): void
    {
        Schema::table('strategy_stats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('run_id');
            $table->dropColumn([
                'avg_win_r', 'avg_loss_r', 'profit_factor', 'expectancy_r', 'max_drawdown_r',
                'total_return_r', 'net_pnl', 'avg_bars_held', 'max_consecutive_losses',
                'best_trade_r', 'worst_trade_r',
            ]);
        });
    }
};
