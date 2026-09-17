<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local storage for daily and weekly OHLCV.
     *
     * Every scan and every backtest currently re-downloads from Yahoo, which makes a run
     * slow and — more importantly — not reproducible: two runs of identical code return
     * different numbers because of data revisions and symbols dropped to rate-limiting.
     * Walk-forward validation and parameter sweeps are not viable against a remote feed.
     *
     * Date-keyed intervals only (1d, 1wk). Five-minute data is deliberately not stored:
     * Yahoo only keeps 60 days of it, and it would be ~2.25M rows for the same universe.
     *
     * A surrogate id rather than a composite primary key on (symbol, interval, date) —
     * Eloquent does not handle composite keys well, and the unique constraint gives the
     * same guarantee and serves the same reads.
     */
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32);
            $table->string('interval', 8);
            $table->date('date');

            $table->decimal('open', 14, 4);
            $table->decimal('high', 14, 4);
            $table->decimal('low', 14, 4);
            $table->decimal('close', 14, 4);

            // Split *and* dividend adjusted. The strategies read `close`, which is split
            // adjusted only — a dividend gap is a real move a trader lived through — but
            // keeping this makes total-return work possible without refetching.
            $table->decimal('adj_close', 14, 4)->nullable();

            $table->unsignedBigInteger('volume');
            $table->timestamp('fetched_at');

            // Every read is "this symbol, this interval, over a date range", and this is
            // also what makes a re-sync an upsert rather than a duplicate.
            $table->unique(['symbol', 'interval', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
