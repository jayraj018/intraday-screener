<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The last status the owner was told about, so every change is reported exactly once. */
    public function up(): void
    {
        Schema::table('swing_paper_trades', fn (Blueprint $table) => $table->string('alerted_status', 16)->nullable());
    }

    public function down(): void
    {
        Schema::table('swing_paper_trades', fn (Blueprint $table) => $table->dropColumn('alerted_status'));
    }
};
