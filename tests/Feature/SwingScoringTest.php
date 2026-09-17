<?php

namespace Tests\Feature;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use App\Services\Swing\HoldingPeriodService;
use App\Services\Swing\MarketRegimeService;
use App\Services\Swing\SignalScoringService;
use App\Services\Swing\StrategyAgreementService;
use App\Services\Swing\SwingContext;
use App\Services\Swing\SwingContextBuilder;
use App\Services\Swing\SwingSetup;
use App\Services\Swing\SwingSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwingScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function context(callable $priceAt, ?callable $benchmarkAt = null, int $count = 320): SwingContext
    {
        $benchmarkAt ??= fn ($i) => 100 + $i * 0.05;
        $daily = $benchmark = [];

        for ($i = 0; $i < $count; $i++) {
            $date = date('Y-m-d', strtotime("2024-01-01 +{$i} days"));
            $p = $priceAt($i);
            $b = $benchmarkAt($i);
            $daily[] = ['date' => $date, 'open' => $p, 'high' => $p + 2, 'low' => $p - 2, 'close' => $p, 'volume' => 1_000_000];
            $benchmark[] = ['date' => $date, 'open' => $b, 'high' => $b + 1, 'low' => $b - 1, 'close' => $b, 'volume' => 1_000_000];
        }

        return app(SwingContextBuilder::class)->build('TESTCO', $daily, [], $benchmark,
            app(MarketRegimeService::class)->detect($benchmark));
    }

    protected function plan(float $target1 = 540.0): SwingSetup
    {
        return new SwingSetup(
            symbol: 'TESTCO', strategy: 'Trend Pullback', direction: 'BUY',
            signalDate: '2026-01-01', signalPrice: 500.0,
            stop: 480.0, stopMethod: 'swing_low', target1: $target1,
        );
    }

    protected function signal(string $strategy, string $state = SwingSignal::READY, string $direction = 'BUY'): SwingSignal
    {
        return new SwingSignal($strategy, 'TESTCO', $state, setup: $state === SwingSignal::READY
            ? new SwingSetup('TESTCO', $strategy, $direction, '2026-01-01', 500.0, 480.0, 'swing_low', 540.0)
            : null);
    }

    // ------------------------------------------------------------- scoring

    public function test_a_strong_setup_scores_higher_than_a_weak_one(): void
    {
        $scorer = app(SignalScoringService::class);

        $strong = $scorer->score($this->context(fn ($i) => 100 + $i * 0.9), $this->plan())['score'];
        $weak = $scorer->score($this->context(fn ($i) => 400 - $i * 0.9), $this->plan())['score'];

        $this->assertGreaterThan($weak, $strong);
    }

    public function test_the_score_stays_within_its_range(): void
    {
        $scorer = app(SignalScoringService::class);

        foreach ([fn ($i) => 100 + $i * 0.9, fn ($i) => 400 - $i * 0.9, fn ($i) => 100 + sin($i / 3) * 5] as $prices) {
            $score = $scorer->score($this->context($prices), $this->plan())['score'];

            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }
    }

    /**
     * The double-count this grouping exists to prevent. Price above the 20 EMA, the 20
     * above the 50, and the 50 rising are three views of one trend — a group must
     * contribute at most its own weight however many of its members agree.
     */
    public function test_a_group_can_never_exceed_its_own_weight(): void
    {
        config(['swing.scoring.weights' => [
            'trend' => 20, 'momentum' => 15, 'volume' => 15, 'relative_strength' => 15,
            'price_structure' => 15, 'market_regime' => 10, 'risk_reward' => 10,
        ]]);

        $result = app(SignalScoringService::class)->score($this->context(fn ($i) => 100 + $i * 0.9), $this->plan());

        foreach ($result['groups'] as $name => $group) {
            $this->assertLessThanOrEqual($group['weight'], $group['points'],
                "the {$name} group scored more than its weight — correlated features are compounding");
        }
    }

    public function test_every_group_explains_its_contribution(): void
    {
        $result = app(SignalScoringService::class)->score($this->context(fn ($i) => 100 + $i * 0.9), $this->plan());

        $this->assertSame(
            ['trend', 'momentum', 'volume', 'relative_strength', 'price_structure', 'market_regime', 'risk_reward'],
            array_keys($result['groups'])
        );

        foreach ($result['groups'] as $group) {
            $this->assertNotEmpty($group['detail']);
        }
    }

    public function test_weights_are_configurable(): void
    {
        $context = $this->context(fn ($i) => 100 + $i * 0.9);

        config(['swing.scoring.weights.trend' => 20]);
        $normal = app(SignalScoringService::class)->score($context, $this->plan())['groups']['trend']['points'];

        config(['swing.scoring.weights.trend' => 50]);
        $heavier = app(SignalScoringService::class)->score($context, $this->plan())['groups']['trend']['points'];

        $this->assertGreaterThan($normal, $heavier);
    }

    public function test_a_better_reward_to_risk_scores_better(): void
    {
        $context = $this->context(fn ($i) => 100 + $i * 0.9);
        $scorer = app(SignalScoringService::class);

        $modest = $scorer->score($context, $this->plan(target1: 520.0))['groups']['risk_reward']['points'];
        $generous = $scorer->score($context, $this->plan(target1: 560.0))['groups']['risk_reward']['points'];

        $this->assertGreaterThan($modest, $generous);
    }

    // --------------------------------------------------------- agreement

    /** Four strategies from one family is one piece of evidence, not four. */
    public function test_correlated_strategies_count_as_one_family(): void
    {
        $assessment = app(StrategyAgreementService::class)->assess([
            $this->signal('MA Trend Following'),
            $this->signal('Trend Pullback'),
        ]);

        $this->assertSame(2, $assessment['strategies']);
        $this->assertSame(1, $assessment['families'], 'both read the 20/50 relationship');
        $this->assertFalse($assessment['qualifies']);
    }

    public function test_strategies_from_different_families_are_independent_evidence(): void
    {
        $assessment = app(StrategyAgreementService::class)->assess([
            $this->signal('MA Trend Following'),
            $this->signal('Breakout + Volume'),
        ]);

        $this->assertSame(2, $assessment['families']);
        $this->assertTrue($assessment['qualifies']);
    }

    public function test_setups_pointing_both_ways_are_not_agreement(): void
    {
        $assessment = app(StrategyAgreementService::class)->assess([
            $this->signal('MA Trend Following', direction: 'BUY'),
            $this->signal('RSI Trend Reversal', direction: 'SELL'),
        ]);

        $this->assertSame(0, $assessment['families']);
        $this->assertFalse($assessment['qualifies']);
        $this->assertNull($assessment['direction']);
    }

    public function test_watchlist_entries_are_not_votes(): void
    {
        $assessment = app(StrategyAgreementService::class)->assess([
            $this->signal('MA Trend Following', SwingSignal::WATCHLIST),
            $this->signal('Breakout + Volume', SwingSignal::WATCHLIST),
        ]);

        $this->assertSame(0, $assessment['strategies']);
        $this->assertFalse($assessment['qualifies']);
    }

    public function test_the_description_says_when_strategies_overlap(): void
    {
        $service = app(StrategyAgreementService::class);

        $overlapping = $service->describe($service->assess([
            $this->signal('MA Trend Following'),
            $this->signal('Trend Pullback'),
        ]));

        $this->assertStringContainsString('overlap', $overlapping);
    }

    // ---------------------------------------------------- holding period

    public function test_the_holding_estimate_refuses_to_guess(): void
    {
        $estimate = app(HoldingPeriodService::class)->estimate('Trend Pullback');

        $this->assertFalse($estimate['known']);
        $this->assertSame('NOT ENOUGH DATA', $estimate['label']);
        $this->assertNull($estimate['median']);
    }

    public function test_the_holding_estimate_comes_from_recorded_trades(): void
    {
        config(['swing.holding_period.min_trades' => 30]);

        $run = BacktestRun::create([
            'system' => 'swing', 'range' => '2y', 'symbols' => 1,
            'settings' => [], 'started_at' => now(), 'finished_at' => now(),
        ]);

        foreach (range(1, 40) as $i) {
            BacktestTrade::create([
                'run_id' => $run->id, 'system' => 'swing', 'symbol' => 'TESTCO',
                'strategy' => 'Trend Pullback', 'direction' => 'BUY',
                'signal_date' => '2026-01-01', 'signal_price' => 500,
                'entry_date' => '2026-01-02', 'entry_price' => 502,
                'exit_date' => '2026-01-10', 'exit_price' => 540, 'exit_reason' => 'target1',
                'quantity' => 100, 'gross_pnl' => 3800, 'costs' => 200, 'net_pnl' => 3600,
                'r_multiple' => 1.8, 'bars_held' => $i % 20 + 1,
            ]);
        }

        $estimate = app(HoldingPeriodService::class)->estimate('Trend Pullback');

        $this->assertTrue($estimate['known']);
        $this->assertSame(40, $estimate['trades']);
        $this->assertStringContainsString('trading days', $estimate['label']);
        $this->assertGreaterThan(0, $estimate['median']);
        $this->assertLessThanOrEqual($estimate['upper'], $estimate['median']);
    }

    public function test_intraday_trades_never_leak_into_a_swing_estimate(): void
    {
        config(['swing.holding_period.min_trades' => 5]);

        $run = BacktestRun::create([
            'system' => 'intraday', 'range' => '2y', 'symbols' => 1,
            'settings' => [], 'started_at' => now(), 'finished_at' => now(),
        ]);

        foreach (range(1, 50) as $i) {
            BacktestTrade::create([
                'run_id' => $run->id, 'system' => 'intraday', 'symbol' => 'TESTCO',
                'strategy' => 'Trend Pullback', 'direction' => 'BUY',
                'signal_date' => '2026-01-01', 'signal_price' => 500,
                'entry_date' => '2026-01-02', 'entry_price' => 502,
                'exit_date' => '2026-01-02', 'exit_price' => 505, 'exit_reason' => 'session_close',
                'quantity' => 100, 'gross_pnl' => 300, 'costs' => 33, 'net_pnl' => 267,
                'r_multiple' => 0.2, 'bars_held' => 1,
            ]);
        }

        $this->assertFalse(app(HoldingPeriodService::class)->estimate('Trend Pullback')['known']);
    }
}
