<?php

namespace App\Console\Commands;

use App\Models\ScreenerResult;
use App\Services\ScreenerService;
use Illuminate\Console\Command;

class RunMorningScreener extends Command
{
    protected $signature = 'screener:run';

    protected $description = 'Scan the watchlist for intraday setups based on configured rules and save results';

    public function handle(ScreenerService $screener): int
    {
        $this->info('Running morning screener...');

        $results = $screener->run();

        $today = now()->toDateString();

        // Clear any previous run for today so re-running doesn't duplicate rows
        ScreenerResult::where('scan_date', $today)->delete();

        foreach ($results as $result) {
            ScreenerResult::create([...$result, 'scan_date' => $today]);
        }

        $this->info(count($results) . ' setup(s) found and saved for ' . $today . '.');

        if (empty($results)) {
            $this->comment('No crossovers today — that\'s normal, this strategy doesn\'t signal every day.');
        }

        return self::SUCCESS;
    }
}
