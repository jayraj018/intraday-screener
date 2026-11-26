<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NSE swing research (research/FINAL_SWING_TRADING_RESEARCH.md)
    |--------------------------------------------------------------------------
    |
    | The research tested 42 pre-registered rules on official NSE bhavcopy data from
    | 26 Nov 2024 to 25 Nov 2026. None passed every pre-registered gate. The Gap-and-Hold
    | with Delivery pattern was the only one positive in Development, Validation and Test,
    | but it produced 75 trades in two years and failed the 100-trade Development gate.
    |
    | It is therefore shown as a RESEARCH WATCH pattern: detected, explained and paper-tracked,
    | never presented as a validated or profitable opportunity, and not part of the swing scan,
    | Top Picks, Telegram alerts or the paper account.
    */

    // Where the research outputs live (CSV/JSON/PNG written by research/src/*.py)
    'path' => base_path('research'),

    'bhavcopy_url' => 'https://nsearchives.nseindia.com/products/content/sec_bhavdata_full_%s.csv',

    'gap_hold_delivery' => [
        'name' => 'Gap-and-Hold with Delivery',
        'status' => 'RESEARCH WATCH — NOT VALIDATED',
        'min_gap' => 0.02,            // open >= 1.02 x previous close
        'min_clv' => 0.6,             // close in the top 40% of the day's range
        'min_relative_volume' => 2.0, // vs 20-session average, today excluded
        'volume_lookback' => 20,
        'atr_period' => 14,
        'min_history' => 35,          // sessions of stored bars before a symbol is judged
        'min_price' => 20,
        'min_median_turnover' => 5e7, // Rs 5 crore, 20-session median
        // exit rule E2 from the research, unchanged
        'stop_atr' => 2.0,
        'trail_atr' => 3.0,
        'max_hold' => 20,
        'max_entry_gap' => 0.02,
        'slippage' => 0.0010,
        'stop_slippage' => 0.0025,
        'reference_target_r' => 2.0,
        'capital' => 50000,
        'risk_percent' => 1.0,
        // the research backtest, for the evidence panel (research/strategy_comparison.csv)
        'research_strategy' => 'R3 Gap-and-Hold with Delivery',
    ],
];
