<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReportsRunStatus;
use App\Models\ScreenerResult;
use App\Services\ScreenerService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;

class RunMorningScreener extends Command implements Isolatable
{
    use ReportsRunStatus;

    public const STATUS_CACHE_KEY = 'screener.status';

    protected $signature = 'screener:run';

    protected $description = 'Scan the watchlist for intraday setups based on configured rules and save results';

    public function handle(ScreenerService $screener): int
    {
        return $this->withRunStatus(fn () => $this->runScreener($screener));
    }

    protected function runScreener(ScreenerService $screener): int
    {
        $total = count($screener->watchlist());

        $this->info('Running morning screener on ' . $total . ' stocks...');

        $done = 0;
        $results = $screener->run(fn () => $this->recordProgress(++$done, $total));

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
