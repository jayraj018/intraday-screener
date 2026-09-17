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

    // NSE regular session close, IST. Before this, the current day's daily candle is
    // still forming and is excluded from every daily strategy.
    'market_close' => '15:30',

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

    // ...and only if they actually made money after costs. Expectancy is the average R
    // returned per trade, so 0 means "at least break even". This is the gate that stops a
    // strategy with a flattering win rate and oversized losers from getting a vote.
    'min_expectancy_r' => 0.0,

    // ...and only if the backtest produced at least this many trades, so a lucky handful doesn't qualify
    'min_backtest_trades' => 20,

    // "Can I trade this now?" on the search page. These bound a live intraday entry:
    // it is only offered while the risk is small enough to be worth taking and there
    // is enough of the session left to manage the trade.
    'live' => [
        // Widest stop, as a % of the price, that still counts as a tradeable entry.
        // Above this, price has run too far from its support to enter safely — the
        // answer is to wait for a pullback, not to take a bigger loss.
        'max_risk_percent' => 2.5,

        // The latest candle's volume must be at least this multiple of the recent
        // average, so an entry isn't taken into a dead tape
        'min_relative_volume' => 1.0,

        // The stop sits this far beyond the level it is based on, so ordinary noise
        // around VWAP or the EMA doesn't trigger it
        'stop_buffer' => 0.005,

        // No new intraday entry after this time, and everything squared off by close
        'no_new_entry_after' => '15:00',
        'square_off_at' => '15:15',
    ],

    // How the backtest assumes a signal is filled.
    //
    //   next_open    - enter at the next session's open. A signal found at a day's close
    //                  can't be bought at that close, so this is the realistic model.
    //   signal_close - enter at the closing price that produced the signal. This is what
    //                  the backtest used to do; it is kept only so the difference between
    //                  the two can be measured rather than argued about.
    'execution' => [
        'entry' => env('BACKTEST_EXECUTION', 'next_open'),

        // Applied against you on both legs, in basis points (5 = 0.05%)
        'slippage_bps' => 5,
    ],

    // Turning a per-share move into money, so costs can be charged realistically and
    // a ₹0.01 gain stops counting as a win.
    'costs' => [
        // Position sizing. Quantity is risk-based: risk budget / distance to the stop,
        // capped so one position can't exceed max_position_value.
        'capital' => 100000,
        'risk_per_trade_percent' => 1.0,
        'max_position_value' => 100000,

        // Indian intraday equity (MIS) charges, as percentages of turnover. These follow
        // a discount broker's published rates — check them against your own contract note.
        'brokerage_percent' => 0.03,
        'brokerage_cap' => 20,       // ₹ per executed order, whichever is lower
        'stt_buy_percent' => 0.0,    // intraday pays STT on the sell leg only
        'stt_sell_percent' => 0.025,
        'exchange_txn_percent' => 0.00297,
        'sebi_percent' => 0.0001,
        'stamp_duty_buy_percent' => 0.003,
        'gst_percent' => 18,         // on brokerage + exchange + SEBI
    ],

    // Secret required to start a backtest from the web (/api/run-backtest?token=...).
    // Leave unset to disable that URL entirely.
    'backtest_token' => env('BACKTEST_TOKEN'),
];
