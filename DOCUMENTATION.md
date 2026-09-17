# Intraday Screener — Full Documentation

A rules-based NSE intraday stock screener built with PHP 8 / Laravel / PostgreSQL.

It scans NSE stocks, applies seven technical rules, and shows which stocks triggered a setup
today — with an entry price, a stop-loss and a target for each.

**It is a research and screening tool, not a signal service.** It tells you what the rules
found. It does not predict what a stock will do, and it cannot tell you whether a setup will
make money.

---

## Table of contents

1. [The two jobs — read this first](#1-the-two-jobs--read-this-first)
2. [Setup and installation](#2-setup-and-installation)
3. [How to run it](#3-how-to-run-it)
4. [Daily routine](#4-daily-routine)
5. [The dashboard, panel by panel](#5-the-dashboard-panel-by-panel)
6. [The seven strategies](#6-the-seven-strategies)
7. [How the backtest works](#7-how-the-backtest-works)
8. [Reading the numbers](#8-reading-the-numbers)
9. [What the results say right now](#9-what-the-results-say-right-now)
10. [Code map](#10-code-map)
11. [Database](#11-database)
12. [Configuration](#12-configuration)
13. [API reference](#13-api-reference)
14. [Tests](#14-tests)
15. [Deployment](#15-deployment)
16. [Troubleshooting](#16-troubleshooting)
17. [Known limitations](#17-known-limitations)

---

## 1. The two jobs — read this first

This is the single most confusing thing about the system.

There are **two separate background jobs**. They write to different tables and show up in
different parts of the dashboard.

> **Finishing the backtest does NOT put stocks on your dashboard.**

|                | Screener                          | Backtest                                     |
| -------------- | --------------------------------- | -------------------------------------------- |
| Command        | `screener:run`                    | `screener:backtest`                          |
| URL            | `/api/run-screener?token=...`     | `/api/run-backtest?token=...`                |
| Status URL     | `/api/screener-status`            | `/api/backtest-status`                       |
| Writes to      | `screener_results`                | `backtest_trades`, `strategy_stats`          |
| Takes about    | 15 minutes                        | 20 minutes                                   |
| You see        | **The stock list** on the dashboard | **Backtest Results panel**, and which strategies count in Top Picks |

The screener asks *"which stocks triggered a rule today?"*
The backtest asks *"has this rule made money over the past two years?"*

```
screener:run  ──►  screener_results  ──►  Stock list on dashboard
                                     │
                                     └──►  Top Picks
                                            ▲
screener:backtest ──► backtest_trades ──► strategy_stats
                                     │        │
                                     │        └─ decides WHICH strategies
                                     │           are allowed a vote
                                     └──►  Backtest Results panel
```

Top Picks is the only place the two meet. It takes today's setups from the screener, keeps
only the strategies the backtest proved profitable, and shows stocks where two or more of
those agree.

**If your dashboard says "No setups found today", the screener has not run.** Running the
backtest will not fix it.

---

## 2. Setup and installation

### Requirements

- PHP 8.2+ with the `pdo_pgsql` extension
- Composer
- Node.js 22+ (only to build front-end assets)
- PostgreSQL 13+

### Install

```bash
git clone <your-repo-url>
cd intraday-screener

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
```

### Configure the database

Edit `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=intraday_screener
DB_USERNAME=postgres
DB_PASSWORD=your_password
```

### Set the secret for background jobs

```env
BACKTEST_TOKEN=some-long-random-string
```

Generate one with:

```bash
php -r "echo bin2hex(random_bytes(24));"
```

Leave `BACKTEST_TOKEN` empty to disable the run-from-URL feature entirely.

### Run migrations

```bash
php artisan migrate
```

### Start it

```bash
php artisan serve
```

Open <http://localhost:8000>.

> **Common local problem:** if you see `could not find driver`, PHP does not have the
> PostgreSQL extension. In XAMPP, uncomment `extension=pdo_pgsql` in `php.ini` and restart.

---

## 3. How to run it

### Option A — command line (local)

```bash
# Scan for today's setups (~15 min)
php artisan screener:run

# Replay 2 years of history (~20 min)
php artisan screener:backtest
```

### Option B — from a URL (production)

A web request is killed after 30 seconds, and Render's free plan has no shell, cron or
worker. So these URLs launch the command as a **detached background process** and return
immediately.

```
https://your-app/api/run-screener?token=YOUR_TOKEN
https://your-app/api/run-backtest?token=YOUR_TOKEN
```

Then poll the status until it is finished:

```
https://your-app/api/screener-status
https://your-app/api/backtest-status
```

Status response:

```json
{
  "run": {
    "state": "running",
    "started_at": "2026-09-17T06:39:27+00:00",
    "heartbeat_at": "2026-09-17T06:51:09+00:00",
    "progress": "238/529"
  },
  "strategies_saved": 7,
  "last_saved_at": "2026-09-17 04:39:36"
}
```

`state` is one of: `never_run`, `starting`, `running`, `finished`, `failed`.

> **Security note:** the token travels in the URL, so it ends up in server access logs and
> your browser history. Rotate it if you have shared a URL containing it.

---

## 4. Daily routine

The screener is scheduled for **09:05 IST on weekdays**, ten minutes before the market
opens. At that hour the last daily candle is yesterday's finished one, which is what the
rules need.

| Time (IST)      | What happens                                                  |
| --------------- | ------------------------------------------------------------- |
| 09:05           | Screener runs automatically, scans ~529 stocks                |
| ~09:20          | Scan finishes — all rows appear at once                       |
| 09:15 – 15:30   | Market open. Use **"Can I trade this now?"** for live entries |
| Saturday 06:00  | Backtest runs automatically                                   |

**Rows appear all at once at the end of the scan**, not gradually. The page stays empty
until the scan completes, then fills. Refresh your browser — there is no auto-refresh for
new rows (only live prices refresh, every 30 seconds).

### Important: do not run the screener during market hours

Between 09:15 and 15:30 today's daily candle is still forming. The app now excludes it
automatically, so a mid-day scan simply produces **yesterday's** signals. That is correct
behaviour, but it means a mid-day run tells you nothing new.

Run before 09:15 or after 15:30.

---

## 5. The dashboard, panel by panel

The most important thing to understand:

> **Some panels show a price you can still get. Others show a price that has already gone.**

| Panel                  | Price shown                        | Can you act on it?                     |
| ---------------------- | ---------------------------------- | -------------------------------------- |
| Can I trade this now?  | Live, this second                  | **Yes** — this is the one to act on    |
| ORB + VWAP             | The fill the breakout got earlier  | No — a record of what already happened |
| Active Trading Setups  | Yesterday's close                  | No — entry would be the next open      |
| Top Picks              | Yesterday's close                  | No — same as above                     |
| Backtest Results       | Past two years                     | No — history, not a signal             |

### Can I trade this now?

Checks five rules against the **live** price and answers **Buy now / Sell now / Wait**.

```
⚡ Can I trade this now?                        ▼ SELL NOW

✓ Price vs VWAP     ₹1,240.50 below ₹1,244.59
✓ Price vs 20 EMA   ₹1,240.50 below ₹1,243.30
✓ Volume            last full 5m bar 83,707 vs 75,742 avg (1.11x)
✓ Risk to support   0.83%  (limit 2.5%)
✓ Session time      open until 15:15

ENTRY  ₹1240.50   STOP ₹1250.81 (-0.83%)   TARGET ₹1219.88 (+1.66%)
```

The five rules:

1. **Price vs VWAP** — price must be clearly on one side
2. **Price vs 20 EMA** — must agree with VWAP, or there is no side to take
3. **Volume** — the last *complete* 5-minute bar must be at or above the recent average
4. **Risk to support** — the stop must be within 2.5% of price, otherwise price has run too far
5. **Session time** — no new entries after 15:00

When it says **Wait**, it names the rule that failed and what to watch for. Expect Wait most
of the time — the volume rule needs *above average*, which by definition happens less than
half the time.

The stop is **structure-based**: just beyond whichever of VWAP or the 20 EMA is further
away, so ordinary noise around the nearer level does not trigger it.

### ORB + VWAP

The opening-range breakout, if one happened today. Shows the time it triggered, the fill it
got, and whether the trade is still open or already closed with its P&L.

```
🎯 Live 15m Opening Range Breakout (ORB) + VWAP     TRAILING STOP HIT (VWAP) 🟢

Price closed outside the opening range (₹292.55 - ₹300.50) at 09:30 IST,
confirmed by VWAP & volume.

ORB High ₹300.50   ORB Low ₹292.55   Trailing SL (VWAP) ₹310.76

Entry — filled at the 09:35 open   Initial Stop-Loss      Target (1:2 R:R)   Closed 09:55
₹306.00 (BUY (Long))               ₹292.55 (4.40% risk)   ₹332.90            +₹1.60 (+0.52%)
```

If it says `Closed 09:55 at ₹307.60`, **that trade is over**. The price at the top of the
panel is the current price and has nothing to do with that entry.

### Active Trading Setups

Daily-candle strategies that fired on the **last completed close**. During market hours
today's candle is still forming and is excluded, so these are yesterday's signals and the
entry would be the next session's open.

A note at the top of the panel says exactly which close the signal came from.

### Top Picks

Stocks where two or more **profitable** strategies agree today.

A strategy only gets a vote if the backtest shows it:

- traded at least 20 times, **and**
- had a win rate at or above 45%, **and**
- had an expectancy above 0 (i.e. it made money after costs)

If no strategy qualifies, the panel says so and explains which gate they failed.

### Backtest Results

Every strategy's real numbers: trades, win rate, average win and loss, profit factor,
expectancy, max drawdown, and how the trades ended.

Profit factor and expectancy are colour-coded — red when losing, green when winning.

---

## 6. The seven strategies

Six run on daily candles. One runs on 5-minute candles.

| Strategy               | Triggers when                                                            | Data      |
| ---------------------- | ------------------------------------------------------------------------ | --------- |
| MA Crossover           | 9-day SMA crosses the 21-day SMA                                         | Daily     |
| RSI Reversal           | RSI(14) crosses back **above 30**, or back **below 70**                  | Daily     |
| Bollinger Breakout     | Close crosses outside the 20-period, 2σ band                             | Daily     |
| MACD Crossover         | MACD line crosses its signal line (12 / 26 / 9)                          | Daily     |
| Volume Breakout        | Volume ≥ 2.5× the 20-day average                                         | Daily     |
| High Momentum          | Price moves ±3% from the previous close                                  | Daily     |
| ORB + VWAP             | Price closes outside the 09:15–09:30 range, above VWAP, on rising volume | 5-minute  |

An eighth label, **Positive Earnings**, appears on the dashboard but can never be backtested
— historical news headlines are not available — so it never counts towards Top Picks.

### Two things worth knowing

**They differ only in the entry trigger.** The stop and target are identical across all
seven: stop at `1.5 × ATR`, target at twice the risk (`3 × ATR`). So the strategies are
really seven ways of picking a day, sharing one risk model.

**The names say intraday, but six of them are not.** They read daily candles and enter at
the next day's open. Only ORB uses intraday data.

### RSI Reversal is not the naive version

It does **not** buy when `RSI < 30`. It waits for RSI to cross back **above** 30 — a
recovery, not a falling knife. Same on the short side at 70.

---

## 7. How the backtest works

It replays two years of history, one day at a time, and records **every trade** it would
have taken.

```
Day D closes
     │
     ├─ no rule triggered ──► next day
     │
     └─ rule triggered
              │
        Day D+1 opens
              │
        BUY at the open + slippage
              │
        During day D+1:
              ├─ hits stop     ──► exit at stop
              ├─ hits target   ──► exit at target
              └─ neither       ──► exit at the close
                         │
                   charge costs, record the trade
```

### Three rules that keep it honest

**1. Entry is the next session's open.**
A signal found at a day's close cannot be bought at that close — the price has gone.
Entering at the next open is the first price actually available.

The old behaviour is still selectable via `execution.entry = signal_close`, kept so the
difference can be **measured** rather than argued about.

**2. Costs are charged.**
Brokerage (with the ₹20 cap), STT, exchange and SEBI fees, stamp duty, GST and slippage.

Worked example — 100 shares bought at ₹306.00, sold at ₹307.60:

```
Gross                              +₹160.00
Brokerage (both legs)                ₹18.41
STT (sell side, 0.025%)               ₹7.69
Exchange + SEBI + stamp               ₹2.80
GST (18%)                             ₹3.65
                                   ─────────
Net                                +₹127.45   (+0.42%)
```

About **20% of the profit goes to costs**, on a winning trade.

**3. A win means net profit after costs.**
Previously a close ₹0.01 above entry counted as a win. After costs, that is a loss.

### Deliberately pessimistic

When a daily candle touches **both** the stop and the target, there is no way to know which
came first, so it is counted as a **loss**. Being wrong in the pessimistic direction is the
only safe way to be wrong here.

### Position sizing

Quantity comes from the risk budget, not a fixed lot:

```
quantity = (capital × risk%) ÷ distance to the stop
```

With ₹1,00,000 capital and 1% risk, a ₹10 stop gives 100 shares. This is what makes results
comparable between a ₹100 stock and a ₹3,000 one.

If the stop is so wide that even one share would breach the budget, the trade is skipped —
it genuinely could not have been taken.

---

## 8. Reading the numbers

### R — the unit everything uses

> **1R is the amount you risked on that trade.**

Buy at ₹500 with a stop at ₹480 → 1R = ₹20.
A trade that made ₹40 returned **2R**. One that lost ₹20 returned **−1R**.

Using R instead of rupees lets a trade on a ₹100 stock and one on a ₹3,000 stock be averaged
together fairly.

### The four numbers that matter

| Number          | What it means                        | Good           |
| --------------- | ------------------------------------ | -------------- |
| Win rate        | How often trades finish in profit    | Not enough alone |
| Profit factor   | Money won ÷ money lost               | Above 1.0      |
| Expectancy      | Average R per trade                  | Above 0        |
| Max drawdown    | Worst peak-to-trough fall, in R      | Lower is better |

**Expectancy is the one to look at.** It is the average R the strategy returns per trade
after costs. Below zero means it lost money, whatever its win rate.

### Why win rate alone settles nothing

At a 1:2 payoff you only need **34%** wins to break even. So 48% could be a strong edge — or
nothing — depending on where the losers land.

> A strategy with 45% wins and large winners beats one with 65% wins and larger losers.

That is why Top Picks is gated on **expectancy**, not win rate.

### The exit breakdown — the most useful column

It shows *how* trades ended: at the stop, at the target, or at the session close.

A strategy showing `session close 97%` almost never reaches its own stop or target. Its win
rate is measuring whether the stock drifted up overnight — **not whether the strategy
works**. That column tells you whether a number means anything at all.

---

## 9. What the results say right now

> **BACKTEST RESULT** — 8 stocks, 2 years, 1,462 trades, entry at next open, costs charged.
> Not a live or paper-traded result.

| Strategy           | Trades | Win rate | Avg win | Avg loss | Profit factor | Expectancy |
| ------------------ | -----: | -------: | ------: | -------: | ------------: | ---------: |
| MACD Crossover     |    272 |    43.0% |   0.31R |   −0.36R |          0.64 |    −0.073R |
| MA Crossover       |    192 |    35.9% |   0.35R |   −0.33R |          0.61 |    −0.082R |
| Volume Breakout    |     64 |    46.9% |   0.29R |   −0.43R |          0.58 |    −0.096R |
| RSI Reversal       |    116 |    32.8% |   0.34R |   −0.34R |          0.50 |    −0.113R |
| High Momentum      |    184 |    37.0% |   0.28R |   −0.36R |          0.45 |    −0.126R |
| Bollinger Breakout |    215 |    34.9% |   0.30R |   −0.40R |          0.41 |    −0.152R |
| ORB + VWAP         |    419 |    21.5% |   0.66R |   −0.45R |          0.40 |    −0.212R |

**Every strategy has negative expectancy and a profit factor below 1.0.**

### Why — the targets are never reached

Of roughly 940 daily-strategy trades, **the target was hit zero times.**

| Strategy           | Stop | Target | Session close |
| ------------------ | ---: | -----: | ------------: |
| MA Crossover       |   2% |     0% |           98% |
| RSI Reversal       |   2% |     0% |           98% |
| MACD Crossover     |   3% |     0% |           97% |
| Bollinger Breakout |   4% |     0% |           96% |
| High Momentum      |   5% |     0% |           95% |
| Volume Breakout    |   8% |     0% |           92% |

The stop sits `1.5 × ATR` from entry and the target `3 × ATR`. That asks a single session to
travel **three times the stock's entire average daily range**. It effectively cannot happen.

So 95–98% of trades fell through to *"was the close above entry?"* — a coin flip.

> **This is why the old win rates all sat in a narrow band around 47–52%.**
> The strategies were never trading their own plan: the risk model is sized for a multi-day
> hold, but the trade closes the same day.

### How much to trust this

Take the direction seriously; do not treat it as settled.

- 8 stocks, not the full 529. Small sample.
- **In-sample** — the whole period was replayed at once, with no out-of-sample or
  walk-forward split.
- The universe is today's index membership replayed backwards, so delisted and demoted
  stocks are missing (**survivorship bias**).
- Split and dividend adjustment in the price feed has **not been verified**.

The exit breakdown, though, is structural. Eight stocks or five hundred, a `3 × ATR`
same-day target stays out of reach.

### What to do about it

Two honest options:

1. **Bring the target closer** so it is reachable in one session, and retest.
2. **Stop closing the same day** — let the trades run as swing trades over several days,
   which is what the `1.5 × ATR` risk model was really sized for.

Neither should be chosen without testing it.

---

## 10. Code map

Architecture is **Controller → Service → Eloquent → PostgreSQL**. No repositories, no queued
jobs.

| File | Does what |
| ---- | --------- |
| `app/Services/StockDataService.php` | Fetches candles from Yahoo. The only file that knows about the data provider |
| `app/Services/NseService.php` | Index constituents and board-meeting dates, cached with a last-good fallback |
| `app/Services/IndicatorService.php` | SMA, EMA, RSI, MACD, ATR, Bollinger, VWAP, average volume |
| `app/Services/ScreenerService.php` | All seven strategies, ORB detection and fill, the live trade decision |
| `app/Services/BacktestService.php` | Replays history and produces trades |
| `app/Services/TradeCostService.php` | Position sizing and Indian trading charges |
| `app/Services/BacktestMetricsService.php` | Profit factor, expectancy, drawdown, streaks, exit breakdown |
| `app/Http/Controllers/ScreenerController.php` | Dashboard, deep analysis, live prices |
| `app/Http/Controllers/BacktestController.php` | `/api/backtest-stats` |
| `app/Http/Controllers/BackgroundRunController.php` | Starts and monitors the long jobs |
| `app/Console/Commands/RunMorningScreener.php` | `screener:run` |
| `app/Console/Commands/RunBacktest.php` | `screener:backtest` |
| `app/Console/Concerns/ReportsRunStatus.php` | Heartbeat + progress for long commands |
| `resources/views/screener/index.blade.php` | The whole front end |

### Why jobs are started from a URL

A web request is killed after `max_execution_time` (30s in production), and Render's free
plan has no shell, cron or worker.

So `BackgroundRunController` launches the artisan command as a **detached CLI process** —
which has no time limit and does not hold the single PHP-FPM worker — then returns
immediately. Progress is written to a cache key, which is what the status endpoints read.

The heartbeat matters: free instances sleep and restart, killing the process without it
recording why. A run whose heartbeat is older than 10 minutes is assumed dead, so a new run
is allowed to start.

### The ORB detector — a note on correctness

`calculateOrbSetup()` judges each candle using **only the candles before it**. This is not a
style choice — it is what makes the live dashboard and the backtest pick the same breakout.

An earlier version averaged volume over the whole session, which let a volume spike later in
the day veto an earlier, genuine breakout. The live card then reported a breakout candle
that could only have been chosen with hindsight.

Detection and execution are separate on purpose:

- `calculateOrbSetup()` — finds the breakout (detection only)
- `fillOrbSetup()` — applies the execution model (entry = next candle's open)
- `orbStatus()` — walks the session to report where the trade stands

---

## 11. Database

```
backtest_runs ──┬──► backtest_trades
                └──► strategy_stats
                        ▲
screener_results ┄┄┄┄┄┄┘  (joined in PHP on strategy name)
```

| Table | Holds | Lifetime |
| ----- | ----- | -------- |
| `screener_results` | Today's setups: symbol, signal, entry, stop, target, reason | Deleted and rewritten each scan |
| `strategy_stats` | One row per strategy: win rate, profit factor, expectancy, drawdown, exit breakdown | Replaced each backtest |
| `backtest_runs` | One row per backtest: settings snapshot, range, timestamps | Kept |
| `backtest_trades` | Every replayed trade with entry, exit, costs, net P&L, R multiple | Kept |

A Nifty 500 run produces roughly **100,000 trade rows**, about 15 MB. Trades are written in
chunks as the run proceeds rather than collected in memory, because the production container
allows PHP only 256 MB.

`backtest_runs.settings` stores the execution model and cost rates used, so an old result can
be reproduced and compared against a later one that changed a **setting** rather than the
code.

Both backtest tables carry a `system` column (`intraday` / `swing`) so a future swing engine
can share them without its trades ever being aggregated together with these.

### Indexes

Only one was added: `(run_id, strategy, entry_date)` on `backtest_trades`. Every aggregate
reads exactly that way — this run, this strategy, in date order.

`screener_results` stays unindexed beyond its existing `scan_date`. At 50–200 rows a day,
Postgres will scan it faster than it would use an index. **Indexes were not added blindly.**

---

## 12. Configuration

Everything lives in `config/screener.php`.

### Universe

```php
'universe_index' => 'nifty500',   // nifty50 | nifty100 | nifty200 | nifty500
'watchlist' => ['KOTAKBANK', 'BAJFINANCE', ...],  // always scanned, on top of the index
```

Companies with a board meeting around today are always added too.

### Strategy parameters

```php
'short_ma' => 9,
'long_ma' => 21,
'atr_period' => 14,
'stop_loss_atr_multiplier' => 1.5,   // stop = entry ∓ (ATR × this)
'risk_reward_ratio' => 2,            // target = entry ± (risk × this)
'volume_surge_multiplier' => 1.5,
'min_avg_volume' => 500000,          // skip illiquid stocks
'market_close' => '15:30',           // before this, today's daily candle is excluded
```

### Top Picks gates

```php
'min_strategies_agree' => 2,
'min_win_rate' => 45,
'min_expectancy_r' => 0.0,     // must have made money after costs
'min_backtest_trades' => 20,   // so a lucky handful doesn't qualify
```

### "Can I trade this now?"

```php
'live' => [
    'max_risk_percent' => 2.5,      // widest stop that still counts as tradeable
    'min_relative_volume' => 1.0,   // last full bar vs recent average
    'stop_buffer' => 0.005,         // stop sits this far beyond the level
    'no_new_entry_after' => '15:00',
    'square_off_at' => '15:15',
],
```

> If Wait feels too strict, lower `min_relative_volume` to `0.8`. These defaults are
> sensible starting values, **not validated numbers**.

### Execution model

```php
'execution' => [
    'entry' => env('BACKTEST_EXECUTION', 'next_open'),  // or 'signal_close'
    'slippage_bps' => 5,   // 5 = 0.05%, applied against you on both legs
],
```

### Costs

```php
'costs' => [
    'capital' => 100000,
    'risk_per_trade_percent' => 1.0,
    'max_position_value' => 100000,

    'brokerage_percent' => 0.03,
    'brokerage_cap' => 20,             // ₹ per order, whichever is lower
    'stt_sell_percent' => 0.025,       // sell leg only for intraday
    'exchange_txn_percent' => 0.00297,
    'sebi_percent' => 0.0001,
    'stamp_duty_buy_percent' => 0.003,
    'gst_percent' => 18,
],
```

> These follow a discount broker's published rates. **Check them against your own contract
> note** — they change.

---

## 13. API reference

| Endpoint | Method | Auth | Rate limit | Returns |
| -------- | ------ | ---- | ---------- | ------- |
| `/` | GET | — | — | The dashboard |
| `/screener/analyze?symbol=X` | GET | — | 30/min | Deep analysis for one stock |
| `/api/screener` | GET | — | — | Today's setups as JSON |
| `/api/screener/live` | GET | — | 30/min | Live prices for today's setups |
| `/api/backtest-stats` | GET | — | 60/min | Last backtest's metrics |
| `/api/run-screener` | GET | token | 10/min | Starts the scan |
| `/api/run-backtest` | GET | token | 10/min | Starts the backtest |
| `/api/screener-status` | GET | — | — | Scan progress |
| `/api/backtest-status` | GET | — | — | Backtest progress |

### `/api/backtest-stats`

```json
{
  "label": "BACKTEST RESULT",
  "caveats": [
    "In-sample: the whole period was replayed at once...",
    "The stock universe is today's index membership replayed backwards..."
  ],
  "run": { "id": 1, "range": "2y", "symbols": 529, "entry_model": "next_open" },
  "qualifying_rules": { "min_trades": 20, "min_win_rate": 45, "min_expectancy_r": 0 },
  "strategies": [
    {
      "strategy": "MACD Crossover",
      "trades": 272,
      "win_rate": 43.01,
      "profit_factor": 0.64,
      "expectancy_r": -0.073,
      "max_drawdown_r": 20.93,
      "exit_breakdown": { "stop": 8, "session_close": 264 },
      "counts_in_top_picks": false
    }
  ]
}
```

The `caveats` array is always returned, so the numbers cannot be quoted without their limits
attached.

---

## 14. Tests

```bash
php artisan test
```

23 tests. The important ones:

| Test | Guards against |
| ---- | -------------- |
| `RiskRewardLabellingTest` | A 2R target ever being labelled anything else |
| `TradeCostServiceTest` | Cost arithmetic drifting from a hand calculation |
| `DashboardTest` | A strategy with a good win rate but negative expectancy sneaking into Top Picks |
| `BacktestStatsTest` | The endpoint or panel dropping its caveats |

The specific case from the spec is covered explicitly:

```
Entry = 500, Stop = 480  →  Target 540 must be 2R, never 1.2R
```

---

## 15. Deployment

Deployed on Render using the included `Dockerfile` (nginx + php-fpm, Node build stage for
Vite assets).

### Deploy script

`scripts/00-laravel-deploy.sh` runs on every deploy:

```bash
php artisan migrate --force   # FIRST — cache/session tables must exist before clearing
php artisan config:clear || true
php artisan cache:clear || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Migrations run **first** on purpose. With database-backed cache and sessions, clearing them
on a fresh database fails, and `set -e` would abort the deploy before `migrate` ran.

### Scheduler

`scripts/01-laravel-scheduler.sh` starts a one-minute loop calling `schedule:run`.

> **Render free plan warning:** the instance sleeps after 15 idle minutes and the loop dies
> with it. A 09:05 run only fires if something has already woken the service.
>
> On the free plan, drive the scan from an **external cron** — cron-job.org, GitHub Actions
> or UptimeRobot hitting `/api/run-screener?token=...` on a schedule.
>
> On a paid instance that stays awake, the built-in loop is enough on its own.

The same sleep can kill a long backtest mid-run. Keep the status page open while it works —
polling counts as traffic and keeps the instance awake.

---

## 16. Troubleshooting

### "No setups found today"

The screener has not run. Run `/api/run-screener?token=...`, wait for
`/api/screener-status` to say `finished`, then refresh.

Running the **backtest** will not fix this — see [section 1](#1-the-two-jobs--read-this-first).

### Top Picks is empty

Most likely no strategy passed the expectancy gate — the panel will say so and name the gate
that failed. This is expected given [current results](#9-what-the-results-say-right-now).

If it says *"Nothing has been measured yet"*, run the backtest.

### The entry price changes every time I refresh

This was a bug and is fixed. If you still see it, your deployment is out of date — the fix
excludes today's unfinished daily candle during market hours.

### `could not find driver`

PHP has no `pdo_pgsql` extension. In XAMPP, uncomment `extension=pdo_pgsql` in `php.ini` and
restart.

### A backtest stops partway through

The free instance went to sleep. Keep `/api/backtest-status` open in a tab while it runs.

### "The backtest is already running"

A previous run is still alive, or died less than 10 minutes ago. Wait for the heartbeat to go
stale, then start again.

### Yahoo returns no data for a symbol

Some tickers change after a merger or demerger. `TATAMOTORS` no longer resolves — it split
into `TMPV` (passenger vehicles) and `TMCV` (commercial vehicles).

---

## 17. Known limitations

Listed honestly, in priority order. None of these are hidden by the UI.

| # | Limitation | Impact |
| - | ---------- | ------ |
| 1 | **Survivorship bias** — the universe is today's index membership replayed over 2 years. Delisted and demoted stocks are missing | Results biased upward |
| 2 | **No out-of-sample split** — the whole period is replayed at once. No walk-forward validation | Results are in-sample only |
| 3 | **Corporate actions unverified** — the code never reads `adjclose` or `events`. Whether the price feed is split-adjusted has not been tested | A split could fire phantom signals |
| 4 | **No candle storage** — every scan and backtest re-downloads from Yahoo | Slow, and results are not reproducible between runs |
| 5 | **Strategy logic exists in two places** — `ScreenerService::dailySetups()` and parts of `ScreenerController::analyze()` | The two can drift apart |
| 6 | **ATR is a simple average of True Range**, not Wilder's smoothing |













 Values will not match a charting platform |
| 7 | **Unofficial data provider** — Yahoo's endpoint has no SLA and can rate-limit or change without notice | Scans can silently drop stocks |
| 8 | **No sector data** | Sector confirmation is unavailable, not false |

### How to verify #3 yourself

Pick an NSE stock with a known split in the last two years, fetch it, and inspect the bar
around the ex-date. If it shows an unadjusted cliff, every split in the window fires a
phantom High Momentum signal.

---

## Labelling results

Whenever a number is quoted, it must say what kind of number it is:

| Label | Meaning |
| ----- | ------- |
| `BACKTEST RESULT` | Replayed on historical data |
| `OUT-OF-SAMPLE` | Tested on data not used to build the rule |
| `PAPER TRADED` | Run forward on live data without real money |
| `LIVE` | Traded with real money |
| `NOT TESTED` | No measurement exists |

**Everything in this system is currently `BACKTEST RESULT` or `NOT TESTED`.** There are no
out-of-sample, paper-traded or live results.

This system never claims a strategy is profitable, and never guarantees returns.
