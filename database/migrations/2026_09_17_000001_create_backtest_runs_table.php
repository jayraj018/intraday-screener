<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per backtest. Without this a result can't be reproduced or compared to an
     * earlier one: the settings that produced it (execution model, costs, date range)
     * lived only in whatever config happened to be loaded at the time.
     *
     * `system` separates intraday from swing so the two never share a tally.
     */
    public function up(): void
    {
        Schema::create('backtest_runs', function (Blueprint $table) {
            $table->id();
            $table->string('system')->default('intraday')->index();
            $table->string('range');
            $table->unsignedInteger('symbols');
            $table->jsonb('settings');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtest_runs');
    }
};
