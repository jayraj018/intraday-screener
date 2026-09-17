<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Services\ScreenerService;
use App\Services\Swing\SwingBacktestRunner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class RunSwingBacktest extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'swing.backtest.status';

    protected const INSERT_CHUNK = 500;

    protected $signature = 'swing:backtest
        {--symbols= : comma-separated list; defaults to the whole watchlist}
        {--from= : first date a signal may be taken on}
        {--to= : last date a signal may be taken on}';

    protected $description = 'Replay the swing strategies over stored history and record every trade with its costs';

    public function handle(SwingBacktestRunner $runner, ScreenerService $screener): int
    {
        return $this->withRunStatus(fn () => $this->backtest($runner, $screener));
    }

    protected function backtest(SwingBacktestRunner $runner, ScreenerService $screener): int
    {
        $symbols = $this->option('symbols')
            ? array_map('trim', explode(',', $this->option('symbols')))
            : $screener->watchlist();

        $coverage = $runner->coverage();

        if ($coverage['bars'] === 0) {
            $this->error('No candles stored. Run candles:backfill first.');

            return self::FAILURE;
        }

        $window = $this->option('from') || $this->option('to')
            ? ['from' => $this->option('from') ?: $coverage['from'], 'to' => $this->option('to') ?: $coverage['to']]
            : null;

        $run = BacktestRun::create([
            'system' => 'swing',
            'range' => ($window['from'] ?? $coverage['from']) . ' to ' . ($window['to'] ?? $coverage['to']),
            'symbols' => count($symbols),
            'settings' => [
                'execution' => config('swing.execution'),
                'exits' => config('swing.exits'),
                'risk' => config('swing.risk'),
                'costs' => config('swing.costs'),
                'qualification' => config('swing.qualification'),
                'liquidity' => config('swing.liquidity'),
                'strategies' => config('swing.strategies'),
            ],
            'started_at' => now(),
        ]);

        $this->info(sprintf('Replaying swing strategies over %d stocks (%s), run #%d...',
            count($symbols), $run->range, $run->id));

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();
        $done = 0;
        $stored = 0;

        $totals = $runner->run(
            $symbols,
            function (array $trades) use ($run, &$stored) {
                $stored += $this->store($run->id, $trades);
            },
            function () use ($bar, &$done, $symbols) {
                $bar->advance();
                $this->recordProgress(++$done, count($symbols));
            },
            $window,
        );

        $bar->finish();
        $this->newLine(2);

        if ($stored === 0) {
            $this->warn('No swing trades were produced. That is a result, not an error — these strategies stand aside in most conditions.');
            $this->line(sprintf('%d stocks scanned, %d skipped for insufficient data.', $totals['symbols'], $totals['skipped']));
            $run->update(['finished_at' => now()]);

            return self::SUCCESS;
        }

        $run->update(['finished_at' => now()]);
        $this->info(sprintf('%d stocks replayed, %d skipped, %s trades recorded.',
            $totals['symbols'], $totals['skipped'], number_format($stored)));
        $this->newLine();
        $this->comment("Metrics: php artisan swing:metrics --run={$run->id}");

        return self::SUCCESS;
    }

    protected function store(int $runId, array $trades): int
    {
        $rows = array_map(fn ($trade) => [
            ...$trade->toArray(),
            'run_id' => $runId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $trades);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            BacktestTrade::insert($chunk);
        }

        return count($rows);
    }
}
