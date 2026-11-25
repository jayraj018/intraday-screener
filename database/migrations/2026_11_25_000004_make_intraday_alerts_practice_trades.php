<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Practice trades have a kind and one reason instead of a list of strategies.
     *
     * Each change checks first: the migration that creates this table was published in two
     * versions, and a database may have been built by either.
     */
    public function up(): void
    {
        $has = fn (string $column) => Schema::hasColumn('intraday_alerts', $column);
        $add = array_filter(['kind', 'reason'], fn ($c) => ! $has($c));
        $drop = array_values(array_filter(['strategies', 'tested'], $has));

        Schema::table('intraday_alerts', function (Blueprint $table) use ($add) {
            in_array('kind', $add) && $table->string('kind', 8)->default('signal');    // signal (sent) | random (comparison, never sent)
            in_array('reason', $add) && $table->string('reason', 200)->default('');
        });

        if ($drop) {
            Schema::table('intraday_alerts', fn (Blueprint $table) => $table->dropColumn($drop));
        }
    }

    public function down(): void
    {
        // The strategies behind old alerts are gone, so there is nothing to put back
    }
};
