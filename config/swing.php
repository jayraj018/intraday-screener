<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    */
    'execution' => [
        // A setup found at a daily close can't be bought at that close, so the fill is
        // the next session's open.
        'entry' => 'next_open',

        // Against you on every leg, in basis points (5 = 0.05%)
        'slippage_bps' => 5,

        // A swing position is exposed overnight, so the entry itself can gap. Past this
        // distance from the signal price the setup counts as missed rather than chased —
        // the risk the plan was built around is no longer the risk being taken.
        'max_entry_gap_percent' => 2.0,

        // What happens when a session OPENS beyond a stop or target.
        //
        //   open  - fill at the open. If the stop is ₹500, yesterday closed at ₹510 and
        //           today opens at ₹490, the exit is ₹490. This is what actually happens.
        //   level - fill at the level, pretending ₹500 was available. Kept only so the
        //           cost of the pretence can be measured; it is not realistic.
        'gap_fill' => 'open',

        // Sessions a resting entry order stays live. An idea that needed a week to prove
        // itself was not the same idea by the time it did.
        'entry_trigger_valid_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exits
    |--------------------------------------------------------------------------
    */
    'exits' => [
        // Trading days a position may be held before it is closed at the market.
        // Untested: 5, 10, 15, 20 and 30 all need measuring before one is called best.
        'max_holding_days' => 10,

        // When a single daily candle reaches BOTH the stop and a target, OHLC alone
        // cannot say which came first.
        //
        //   stop_first   - assume the stop. Pessimistic, and the only safe default.
        //   target_first - assume the target. Flatters every result; for comparison only.
        'same_candle' => 'stop_first',

        // Fraction of the position closed at target 1. At 1.0 the trade is a single exit
        // and target 2 is never reached; below 1.0 the rest runs on to target 2 or the
        // trailing stop.
        'scale_out_at_target1' => 1.0,

        'trailing' => [
            //   none               - the stop stays where the setup put it
            //   breakeven_after_1r - once price has travelled 1R, the stop moves to entry
            //   atr_chandelier     - trail below the highest close since entry by
            //                        atr_multiplier x ATR
            'method' => 'none',
            'atr_period' => 14,
            'atr_multiplier' => 3.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Position sizing and charges
    |--------------------------------------------------------------------------
    |
    | These are DELIVERY rates, not intraday. A swing position is held overnight, so it
    | settles as delivery: STT is 0.1% on BOTH legs rather than 0.025% on the sell alone,
    | which is roughly eight times the securities transaction tax an intraday round trip
    | pays. Copying the intraday numbers here would understate the cost of every trade.
    |
    | Check them against your own contract note — they change.
    */
    'costs' => [
        'capital' => 100000,
        'risk_per_trade_percent' => 1.0,
        'max_position_value' => 100000,

        'brokerage_percent' => 0.0,   // many discount brokers charge nothing on delivery
        'brokerage_cap' => 20,
        'stt_buy_percent' => 0.1,
        'stt_sell_percent' => 0.1,
        'exchange_txn_percent' => 0.00297,
        'sebi_percent' => 0.0001,
        'stamp_duty_buy_percent' => 0.015,
        'gst_percent' => 18,
    ],

    /*
    |--------------------------------------------------------------------------
    | Walk-forward validation
    |--------------------------------------------------------------------------
    |
    | Parameters are chosen on the training window, checked on the validation window, and
    | only then measured on a test window that had no part in choosing them. Optimising
    | across the whole history and reporting the result is how a strategy comes to look
    | excellent on paper and lose money live.
    */
    'walk_forward' => [
        'train_months' => 12,
        'validate_months' => 3,
        'test_months' => 3,

        // How far each fold moves forward
        'step_months' => 3,

        //   rolling  - the training window slides, so old regimes drop out
        //   anchored - the training window grows from a fixed start
        'mode' => 'rolling',

        // History the indicators may read before a window's first tradeable day. A
        // 200-day average needs 200 prior days; reading them is warm-up, not look-ahead.
        'warmup_days' => 250,

        // Below this a window's numbers are noise, and a fold that cannot reach it is
        // rejected rather than reported.
        'min_trades' => 30,

        // What the training window picks a winner by
        'objective' => 'expectancy_r',

        // The chosen configuration must still clear these on the validation window. If it
        // does not, the fold contributes no out-of-sample result at all.
        'validation_gate' => [
            'min_expectancy_r' => 0.0,
            'min_profit_factor' => 1.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Market regime and context
    |--------------------------------------------------------------------------
    |
    | A trend-following rule fires constantly in a sideways market and loses on the
    | whipsaws. Knowing which market you are in is what lets a strategy stand aside.
    */
    'regime' => [
        'benchmark' => '^NSEI',      // NIFTY 50

        'adx_period' => 14,
        'adx_trend_threshold' => 25, // below this there is no trend worth following

        'trend_ma_period' => 200,

        // Volatility is judged against the benchmark's own recent history, not a fixed
        // number: "high" only means anything relative to what is normal here.
        'volatility_lookback' => 120,
        'high_volatility_percentile' => 80,
        'low_volatility_percentile' => 20,
    ],

    'relative_strength' => [
        // Several spans, because none of them is known to be the right one. Which
        // matters — if any — is for walk-forward to answer, not for this file to assert.
        'periods' => [20, 50, 100],
    ],

    'weekly' => [
        // Weekly context: price above a rising 20-week EMA is the broader trend a daily
        // setup is taken with or against.
        'trend_ema_period' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk: where the stop goes and where the targets sit
    |--------------------------------------------------------------------------
    */
    'risk' => [
        //   swing_low     - just beyond the last confirmed pivot: the level that says the
        //                   idea was wrong
        //   atr           - a volatility multiple from the trigger
        //   structure_atr - the pivot, but never tighter than a minimum ATR distance, so
        //                   a shallow pullback does not produce a stop inside the noise
        'stop_method' => 'structure_atr',

        'atr_multiplier' => 2.0,
        'swing_lookback' => 5,
        'swing_buffer' => 0.005,   // beyond the pivot, so a retest does not trigger it

        // Targets as multiples of the risk taken. The R label is derived from these, never
        // written by hand.
        'target1_r' => 2.0,
        'target2_r' => 4.0,

        // A setup whose price has already run this far past its trigger is EXTENDED: the
        // move happened without you, and entering now takes a different risk to the plan.
        'max_extension_atr' => 1.0,

        // How close price must come to a trigger before the setup is worth watching
        'watchlist_distance_atr' => 1.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy parameters
    |--------------------------------------------------------------------------
    |
    | Conventional starting values, NOT validated. Which of them earn their place is a
    | question for walk-forward, which is why they live here rather than in the code.
    */
    'strategies' => [
        'ma_trend' => [
            'fast' => 20,
            'slow' => 50,
            'long' => 200,
            'min_adx' => 20,          // below this the averages are crossing in noise
            'slope_lookback' => 10,   // bars used to tell whether an average is rising
        ],

        'breakout_volume' => [
            'resistance_lookback' => 60,
            'min_touches' => 2,           // a level tested once is not a level
            'min_relative_volume' => 1.5,
            'max_extension_percent' => 3.0, // how far above the level still counts
        ],

        'trend_pullback' => [
            'fast' => 20,
            'slow' => 50,
            'max_pullback_atr' => 1.0,  // how close to the average a pullback must come
            'rsi_floor' => 40,          // a pullback, not a collapse
            'rsi_ceiling' => 70,        // and not already overbought
        ],

        'breakout_retest' => [
            'resistance_lookback' => 60,
            'min_touches' => 2,
            'breakout_within_bars' => 12,   // how recently the level was broken
            'retest_distance_atr' => 0.75,  // how close price must come back to it
            'max_loss_below_percent' => 1.5, // a deeper close back under kills the idea
        ],

        'momentum_continuation' => [
            'short_period' => 20,
            'long_period' => 50,
            'min_short_return' => 8.0,      // % over the short period
            'min_relative_volume' => 1.2,
            // Past this distance from its own average the move has already happened
            'max_extension_atr' => 3.0,
        ],

        'rsi_reversal' => [
            'trend_ema' => 50,
            'long_ma' => 200,
            'pullback_rsi' => 45,   // RSI must have weakened to here...
            'recovery_rsi' => 50,   // ...and recovered back above here
            'lookback' => 12,
        ],

        'volatility_contraction' => [
            'width_lookback' => 120,
            'width_percentile' => 25,       // band width in its own bottom quartile
            'contraction_bars' => 10,
            'min_breakout_relative_volume' => 1.5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal score
    |--------------------------------------------------------------------------
    |
    | Features are grouped, and a group contributes the MEAN of its members — never the
    | sum. Price above the 20 EMA, the 20 above the 50, and the 50 rising are three ways
    | of observing one trend; adding them up would let a single piece of information score
    | three times and make a trend-following setup look independently confirmed by things
    | that are really the same thing.
    |
    | These weights are the brief's own example. They are a STARTING POINT, not a finding:
    | nothing here has been validated, and which groups earn their weight is a question
    | for walk-forward. They live in config so that question can be asked.
    */
    'scoring' => [
        'weights' => [
            'trend' => 20,
            'momentum' => 15,
            'volume' => 15,
            'relative_strength' => 15,
            'price_structure' => 15,
            'market_regime' => 10,
            'risk_reward' => 10,
        ],

        // Relative volume that scores full marks; below 1.0 scores nothing
        'volume_saturation' => 2.0,

        // Reward-to-risk that scores full marks
        'rr_saturation' => 3.0,

        // A healthy pullback in an uptrend, rather than exhaustion at either end
        'rsi_band' => [45, 70],
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy agreement
    |--------------------------------------------------------------------------
    |
    | Strategies that read the same information are not independent evidence. MA Trend and
    | Trend Pullback both rest on the 20/50 relationship; counting them as two votes is
    | the same double-count as summing three moving-average features.
    |
    | Agreement is therefore counted in FAMILIES. Four strategies from one family is one
    | piece of evidence, not four.
    */
    'agreement' => [
        'families' => [
            'MA Trend Following' => 'trend',
            'Trend Pullback' => 'trend',
            'Breakout + Volume' => 'breakout',
            'Breakout Retest' => 'breakout',
            'Volatility Contraction' => 'breakout',
            'Momentum Continuation' => 'momentum',
            'RSI Trend Reversal' => 'mean_reversion',
        ],

        // Distinct families that must agree before a stock is a top pick
        'min_families_agree' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Holding period estimate
    |--------------------------------------------------------------------------
    */
    'holding_period' => [
        // Below this many recorded trades the estimate is NOT ENOUGH DATA rather than a
        // number invented from a handful of outcomes.
        'min_trades' => 30,
        'lower_percentile' => 25,
        'upper_percentile' => 75,
    ],

];
