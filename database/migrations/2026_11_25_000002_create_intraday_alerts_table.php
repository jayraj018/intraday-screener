<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One BUY alert per stock per day, with the levels exactly as they were sent, and how it ended. */
    public function up(): void
    {
        Schema::create('intraday_alerts', function (Blueprint $table) {
            $table->id();
            $table->date('alert_date');
            $table->string('symbol', 32);
            $table->string('price_time', 8);      // IST time of the candle the price came from
            $table->decimal('entry', 12, 2);
            $table->decimal('max_entry', 12, 2);
            $table->decimal('stop_loss', 12, 2);
            $table->decimal('target', 12, 2);
            $table->unsignedInteger('quantity');
            $table->jsonb('strategies');
            $table->boolean('tested');

            // The candle the alert was sent in, and how far it had already travelled: only
            // what it did after that can have closed the trade.
            $table->unsignedInteger('candle_at');
            $table->decimal('seen_low', 12, 4);
            $table->decimal('seen_high', 12, 4);

            $table->string('status', 16)->default('OPEN');   // OPEN | TARGET | STOP | TIME
            $table->string('exit_time', 8)->nullable();
            $table->decimal('exit_price', 12, 2)->nullable();
            $table->decimal('net_pnl', 14, 2)->nullable();
            $table->decimal('r_multiple', 10, 4)->nullable();
            $table->boolean('exit_alerted')->default(false);
            $table->timestamps();

            $table->unique(['alert_date', 'symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_alerts');
    }
};
