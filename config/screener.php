<?php

return [

    // The NSE index whose stocks are scanned every run, downloaded fresh from NSE:
    // nifty50, nifty100, nifty200 or nifty500. Companies with a board meeting
    // (results, dividend, fund raising, buyback...) around today are always added too.
    'universe_index' => 'nifty500',

    // Extra stocks to always scan on top of the index. Add/remove NSE symbols as you like (no ".NS" suffix here).
    'watchlist' => [
        'KOTAKBANK',
        'BAJFINANCE',
        'BHARTIARTL',
        'MARUTI',
        'SUNPHARMA',
        // Tata Motors demerged: the old TATAMOTORS ticker no longer resolves. TMPV is
        // the passenger-vehicle arm, TMCV the commercial-vehicle one.
        'TMPV',
        'TMCV',
        'TATASTEEL',
        'WIPRO',
        'hcltech',
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

    // "Top Picks" on the dashboard: a stock is shown only when at least this many
    // different high win-rate strategies give it the same direction (BUY or SELL) on the same day
    'min_strategies_agree' => 2,

    // Backtest (`php artisan screener:backtest`): daily strategies are replayed on this much
    // history (ORB always uses 60 days, Yahoo's limit for 5-minute data). Every trade is
    // closed the same day — at its target, its stop-loss, or the closing price.
    'backtest_range' => '2y',

    // Only strategies with at least this backtested win rate (%) count towards Top Picks...
    'min_win_rate' => 45,

    // ...and only if the backtest produced at least this many trades, so a lucky handful doesn't qualify
    'min_backtest_trades' => 20,
];
