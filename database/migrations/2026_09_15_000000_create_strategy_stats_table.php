<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strategy_stats', function (Blueprint $table) {
            $table->id();
            $table->string('strategy')->unique();
            $table->unsignedInteger('trades');
            $table->unsignedInteger('wins');
            $table->decimal('win_rate', 5, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_stats');
    }
};
