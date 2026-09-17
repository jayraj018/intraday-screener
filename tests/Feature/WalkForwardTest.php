<?php

namespace Tests\Feature;

use App\Services\Swing\WalkForwardService;
use App\Services\Swing\WalkForwardWindow;
use Tests\TestCase;

class WalkForwardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['swing.walk_forward' => [
            'train_months' => 12,
            'validate_months' => 3,
            'test_months' => 3,
            'step_months' => 3,
            'mode' => 'rolling',
            'warmup_days' => 250,
            'min_trades' => 10,
            'objective' => 'expectancy_r',
            'validation_gate' => ['min_expectancy_r' => 0.0],
        ]]);
    }

    protected function harness(): WalkForwardService
    {
        return app(WalkForwardService::class);
    }

    /** Metrics good enough to pass every gate. */
    protected function goodMetrics(float $expectancy = 0.2, int $trades = 100): array
    {
        return [
            'trades' => $trades,
            'expectancy_r' => $expectancy,
            'win_rate' => 45.0,
            'gross_profit_r' => 60.0,
            'gross_loss_r' => 40.0,
        ];
    }

    // ------------------------------------------------------------- splitting

    public function test_folds_run_train_then_validate_then_test_without_overlapping(): void
    {
        $folds = $this->harness()->split('2021-01-01', '2026-01-01');

        $this->assertNotEmpty($folds);

        foreach ($folds as $fold) {
            $this->assertLessThan($fold['validate']->from, $fold['train']->to);
            $this->assertLessThan($fold['test']->from, $fold['validate']->to);
        }
    }

    /**
     * The guarantee the whole exercise rests on: no day used to pick parameters may also
     * be a day the result is measured on.
     */
    public function test_no_test_day_is_ever_a_training_or_validation_day(): void
    {
        foreach ($this->harness()->split('2021-01-01', '2026-01-01') as $fold) {
            $this->assertGreaterThan($fold['train']->to, $fold['test']->from);
            $this->assertGreaterThan($fold['validate']->to, $fold['test']->from);
        }
    }

    public function test_each_fold_steps_forward(): void
    {
        $folds = $this->harness()->split('2021-01-01', '2026-01-01');

        for ($i = 1; $i < count($folds); $i++) {
            $this->assertGreaterThan($folds[$i - 1]['test']->from, $folds[$i]['test']->from);
        }
    }

    public function test_a_rolling_window_slides_and_an_anchored_one_grows(): void
    {
        $rolling = $this->harness()->split('2021-01-01', '2026-01-01');

        config(['swing.walk_forward.mode' => 'anchored']);
        $anchored = $this->harness()->split('2021-01-01', '2026-01-01');

        $this->assertNotSame($rolling[1]['train']->from, $rolling[0]['train']->from, 'rolling should move its start');
        $this->assertSame($anchored[1]['train']->from, $anchored[0]['train']->from, 'anchored should keep its start');
        $this->assertGreaterThan($anchored[0]['train']->to, $anchored[1]['train']->to, 'anchored should grow');
    }

    /** Warm-up is history the indicators may read, not days a signal may be taken on. */
    public function test_every_window_can_read_history_before_its_first_tradeable_day(): void
    {
        foreach ($this->harness()->split('2021-01-01', '2026-01-01') as $fold) {
            foreach ($fold as $window) {
                $this->assertInstanceOf(WalkForwardWindow::class, $window);
                $this->assertLessThan($window->from, $window->warmupFrom);
            }
        }
    }

    public function test_a_range_too_short_for_one_fold_produces_none(): void
    {
        $this->assertSame([], $this->harness()->split('2025-01-01', '2025-06-01'));
    }

    // ------------------------------------------------------------- selection

    public function test_training_picks_the_best_candidate_by_the_objective(): void
    {
        $result = $this->harness()->run('2021-01-01', '2023-01-01',
            [['id' => 'weak'], ['id' => 'strong']],
            fn ($params, $window) => $this->goodMetrics($params['id'] === 'strong' ? 0.4 : 0.05),
        );

        foreach ($result->accepted() as $fold) {
            $this->assertSame('strong', $fold->chosen['id']);
        }
    }

    public function test_a_candidate_that_barely_trades_is_not_selectable(): void
    {
        $result = $this->harness()->run('2021-01-01', '2023-01-01',
            [['id' => 'rare'], ['id' => 'common']],
            fn ($params) => $params['id'] === 'rare'
                ? $this->goodMetrics(9.9, trades: 3)     // spectacular, on three trades
                : $this->goodMetrics(0.1, trades: 500),
        );

        foreach ($result->accepted() as $fold) {
            $this->assertSame('common', $fold->chosen['id'], 'a three-trade fluke must not win');
        }
    }

    // ------------------------------------------------------------ validation

    public function test_a_configuration_that_fails_validation_yields_no_result(): void
    {
        $result = $this->harness()->run('2021-01-01', '2023-01-01',
            [['id' => 'overfit']],
            // Excellent in training, negative everywhere else — the classic overfit
            fn ($params, $window) => $this->goodMetrics($window->phase === 'train' ? 0.9 : -0.3),
        );

        $this->assertSame([], $result->accepted());
        $this->assertNotEmpty($result->rejected());
        $this->assertNull($result->outOfSample());
        $this->assertStringContainsString('NOT TESTED', $result->verdict());
    }

    /**
     * The strongest guarantee here: a rejected fold's test window is never even looked at,
     * so no part of it can leak into the choice of parameters.
     */
    public function test_a_rejected_fold_never_touches_its_test_window(): void
    {
        $phasesSeen = [];

        $this->harness()->run('2021-01-01', '2023-01-01',
            [['id' => 'overfit']],
            function ($params, $window) use (&$phasesSeen) {
                $phasesSeen[] = $window->phase;

                return $this->goodMetrics($window->phase === 'train' ? 0.9 : -0.3);
            },
        );

        $this->assertContains('train', $phasesSeen);
        $this->assertContains('validate', $phasesSeen);
        $this->assertNotContains('test', $phasesSeen, 'the out-of-sample window was opened for a fold that failed validation');
    }

    public function test_a_fold_with_too_few_validation_trades_is_rejected(): void
    {
        $result = $this->harness()->run('2021-01-01', '2023-01-01',
            [['id' => 'thin']],
            fn ($params, $window) => $this->goodMetrics(0.3, trades: $window->phase === 'train' ? 100 : 2),
        );

        $this->assertSame([], $result->accepted());
        $this->assertStringContainsString('too few trades in validation', $result->rejected()[0]->rejectedBecause);
    }

    // ----------------------------------------------------------- out-of-sample

    public function test_the_reported_result_comes_from_the_test_windows(): void
    {
        $result = $this->harness()->run('2021-01-01', '2024-01-01',
            [['id' => 'honest']],
            fn ($params, $window) => $this->goodMetrics(match ($window->phase) {
                'train' => 0.50,
                'validate' => 0.30,
                'test' => 0.12,   // the only number that should surface
            }),
        );

        $this->assertEqualsWithDelta(0.12, $result->outOfSample()['expectancy_r'], 0.0001);
        $this->assertStringContainsString('OUT-OF-SAMPLE', $result->verdict());
    }

    public function test_folds_are_weighted_by_how_much_they_traded(): void
    {
        $calls = 0;

        $result = $this->harness()->run('2021-01-01', '2024-01-01',
            [['id' => 'x']],
            function ($params, $window) use (&$calls) {
                if ($window->phase !== 'test') {
                    return $this->goodMetrics(0.3, trades: 100);
                }

                // Alternate a big losing fold with a tiny winning one
                return $calls++ % 2 === 0
                    ? $this->goodMetrics(-0.2, trades: 900)
                    : $this->goodMetrics(2.0, trades: 10);
            },
        );

        $this->assertLessThan(0, $result->outOfSample()['expectancy_r'],
            'a ten-trade winner must not outweigh a nine-hundred-trade loser');
    }

    /** Best-of-fifty is a weaker claim than best-of-three, and must be visible. */
    public function test_the_result_records_how_many_candidates_were_tried(): void
    {
        $candidates = array_map(fn ($i) => ['id' => $i], range(1, 12));

        $result = $this->harness()->run('2021-01-01', '2023-01-01', $candidates,
            fn () => $this->goodMetrics(),
        );

        $this->assertSame(12, $result->outOfSample()['candidates_per_fold']);
        $this->assertStringContainsString('best of 12 candidates', $result->verdict());
    }

    public function test_profit_factor_is_pooled_not_averaged(): void
    {
        $result = $this->harness()->run('2021-01-01', '2024-01-01',
            [['id' => 'x']],
            fn () => array_merge($this->goodMetrics(), ['gross_profit_r' => 60.0, 'gross_loss_r' => 40.0]),
        );

        $this->assertEqualsWithDelta(1.5, $result->outOfSample()['profit_factor'], 0.0001);
    }
}
