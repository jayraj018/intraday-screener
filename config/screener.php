<?php

return [

    // Stocks to scan every morning. Add/remove NSE symbols as you like (no ".NS" suffix here).
    'watchlist' => [
        'KOTAKBANK',
        'BAJFINANCE',
        'BHARTIARTL',
        'MARUTI',
        'SUNPHARMA',
        'TATAMOTORS',
        'TATASTEEL',
        'WIPRO',
        'ADANIENT',
        'ULTRACEMCO',
        'HINDZINC',
    ],

    // Moving average crossover periods (in days, using daily candles)
    'short_ma' => 9,
    'long_ma' => 21,

    // A stock only counts as "volume confirmed" if today's volume is this many
    // times its 20-day average volume
    'volume_surge_multiplier' => 1.5,

    // ATR (Average True Range) period, used to size the stop-loss based on the
    // stock's own recent volatility rather than a fixed percentage
    'atr_period' => 14,

    // Stop-loss = entry -/+ (ATR * this multiplier)
    'stop_loss_atr_multiplier' => 1.5,

    // Target is set at this multiple of the risk (entry-to-stop-loss distance)
    // e.g. 2 means target is 2x as far from entry as the stop-loss is
    'risk_reward_ratio' => 2,

    // Skip illiquid stocks below this average daily volume
    'min_avg_volume' => 500000,
];
