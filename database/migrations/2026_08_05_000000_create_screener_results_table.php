<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screener_results', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('strategy')->nullable()->index();
            $table->string('signal');
            $table->decimal('entry', 10, 2);
            $table->decimal('stop_loss', 10, 2);
            $table->decimal('target', 10, 2);
            $table->boolean('volume_surge')->default(false);
            $table->string('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->date('scan_date')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screener_results');
    }
};
