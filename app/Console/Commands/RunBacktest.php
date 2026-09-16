<?php

namespace App\Console\Commands;

use App\Models\StrategyStat;
use App\Services\BacktestService;
use App\Services\ScreenerService;
use Illuminate\Console\Command;

class RunBacktest extends Command
{
    protected $signature = 'screener:backtest';

    protected $description = 'Replay each strategy on past data, save its same-day win rate, and decide which count towards Top Picks';

    public function handle(BacktestService $backtest, ScreenerService $screener): int
    {
        $symbols = $screener->watchlist();

        $this->info('Backtesting strategies on ' . count($symbols) . ' stocks (trades closed the same day)...');

        $bar = $this->output->createProgressBar(count($symbols));
        $bar->start();
        $tally = $backtest->run($symbols, fn () => $bar->advance());
        $bar->finish();
        $this->newLine(2);

        if (! $tally) {
            $this->error('No data could be fetched, so nothing was saved. Try again in a moment.');

            return self::FAILURE;
        }

        // Replace the previous backtest entirely so a strategy that no longer trades doesn't keep an old win rate
        StrategyStat::query()->delete();

        $stats = collect($tally)
            ->map(fn ($t, $strategy) => StrategyStat::create([
                'strategy' => $strategy,
                'trades' => $t['trades'],
                'wins' => $t['wins'],
                'win_rate' => round($t['wins'] / $t['trades'] * 100, 2),
            ]))
            ->sortByDesc('win_rate');

        $this->table(
            ['Strategy', 'Trades', 'Wins', 'Win rate', 'Counts in Top Picks'],
            $stats->map(fn (StrategyStat $s) => [
                $s->strategy,
                $s->trades,
                $s->wins,
                $s->win_rate . '%',
                match (true) {
                    $s->qualifies() => 'Yes',
                    $s->trades < config('screener.min_backtest_trades') => 'No (too few trades)',
                    default => 'No',
                },
            ])
        );

        $this->comment('Positive Earnings can\'t be backtested (no old news data), so it never counts towards Top Picks.');

        return self::SUCCESS;
    }
}
