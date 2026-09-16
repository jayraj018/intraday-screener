<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SESSION_DRIVER=database, so every request reads the sessions table. Laravel creates it
 * inside the users migration, which leaves no way to recover if that migration is already
 * recorded as run but the table is missing — as happened on the deployed server:
 * "relation \"sessions\" does not exist".
 *
 * Guarded with hasTable() so it creates the table only where it is absent, and is safe to
 * run on every environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        // Left in place: the users migration owns this table's lifecycle, and dropping it
        // here would sign every visitor out if this migration alone were rolled back.
    }
};
