<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stocks watched each day, written down before the day's result could be known
        Schema::create('intraday_picks', function (Blueprint $table) {
            $table->id();
            $table->date('pick_date');
            $table->string('kind', 8);            // signal | random
            $table->unsignedSmallInteger('position');
            $table->string('symbol', 32);
            $table->decimal('close', 12, 2);
            $table->decimal('return_60', 8, 2);   // percent
            $table->decimal('return_3', 8, 2);
            $table->boolean('announced')->default(false);
            $table->timestamps();

            $table->unique(['pick_date', 'symbol']);
        });

        // One stock's 5-minute prices for one day, compressed: the provider forgets them after
        // 60 days, which is too short a history to test an intraday rule on.
        Schema::create('intraday_bars', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->date('date');
            $table->unsignedSmallInteger('count');
            $table->text('data');
            $table->timestamps();

            $table->unique(['symbol', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intraday_bars');
        Schema::dropIfExists('intraday_picks');
    }
};
