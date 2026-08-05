# Morning Intraday Screener (Laravel)

Scans a watchlist of NSE stocks every morning using a moving-average
crossover strategy confirmed by volume, and outputs entry, stop-loss, and
target for each qualifying stock.

**Important:** This is a rule-based screener, not a prediction engine. It
tells you which stocks match technical rules you define — it does not
guarantee profit. Paper trade its output for a few weeks before using real
money (see Step 4 of the trading guide).

## What it does

- Every weekday at 9:05 AM IST, fetches daily price data for ~20 NSE stocks
  (edit the list in `config/screener.php`)
- Computes a short-term and long-term moving average, ATR (volatility), and
  volume for each stock
- Flags stocks where the short MA just crossed the long MA, calculates a
  stop-loss based on the stock's own volatility (ATR), and a target based on
  a 1:2 risk-reward ratio
- Saves results to the database and shows them on a simple dashboard at `/`

## Setup

1. **Create a fresh Laravel project** (skip if you already have one):
   ```bash
   composer create-project laravel/laravel intraday-screener
   cd intraday-screener
   ```

2. **Copy these files into your project**, matching the same folder
   structure (they'll overwrite the default `routes/web.php` and
   `routes/console.php` — merge manually if you've already customized those):
   ```
   app/Services/StockDataService.php
   app/Services/IndicatorService.php
   app/Services/ScreenerService.php
   app/Models/ScreenerResult.php
   app/Console/Commands/RunMorningScreener.php
   app/Http/Controllers/ScreenerController.php
   config/screener.php
   database/migrations/2026_08_05_000000_create_screener_results_table.php
   resources/views/screener/index.blade.php
   routes/web.php
   routes/console.php
   ```

3. **Set up your database** — edit `.env` with your DB credentials (SQLite
   is fine to start: set `DB_CONNECTION=sqlite` and run
   `touch database/database.sqlite`), then run:
   ```bash
   php artisan migrate
   ```

4. **Run the screener manually** to test it:
   ```bash
   php artisan screener:run
   ```

5. **View the dashboard**:
   ```bash
   php artisan serve
   ```
   Then open `http://localhost:8000`

6. **Automate the morning run** — Laravel's scheduler needs one cron entry
   on your server (or a cloud scheduler like a VPS cron, Laravel Forge, or
   a free service like cron-job.org hitting an artisan-triggering route):
   ```bash
   * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
   ```
   The screener itself only actually runs at 9:05 AM weekdays (see
   `routes/console.php`) — this cron entry just needs to exist so Laravel
   can check the time every minute.

## Customizing the strategy

Everything tunable lives in `config/screener.php`:
- `watchlist` — add/remove NSE stock symbols
- `short_ma` / `long_ma` — crossover periods
- `volume_surge_multiplier` — how unusual volume must be to "confirm" a signal
- `stop_loss_atr_multiplier` — wider = more room before stopped out, more risk per trade
- `risk_reward_ratio` — target distance relative to stop-loss distance

## Known limitations (be aware of these)

- **Data source**: uses Yahoo Finance's free, unofficial endpoint with daily
  (not live intraday) candles — there's no official free real-time NSE feed.
  This is fine for screening the night before / morning of, but do NOT treat
  the "entry" price as a live tick — always check the actual current price
  in your broker's app before placing an order.
- **No paid API required to start**, but if you want live intraday candles,
  swap `StockDataService` for Zerodha Kite Connect or Upstox's API later —
  the rest of the app (indicators, screening logic, dashboard) doesn't need
  to change.
- **This strategy lags**: moving average crossovers confirm a trend after
  it's already started. It will sometimes flag stocks after the best part of
  the move already happened, and it will produce false signals in choppy,
  sideways markets.
- **No backtesting is included.** Before trusting any signal with real
  money, log its outcomes for several weeks (win rate, average win vs.
  average loss) — the journal habit from Step 4 applies here directly.

## Suggested next steps

- Add a backtest command that replays the strategy against historical data
  to see its actual historical win rate before trading it live
- Add email/Telegram notification when the morning scan completes
- Add a second confirming indicator (e.g. RSI) to reduce false signals in
  sideways markets
"# intraday-screener" 
