<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Intraday Practice</title>
    <style>
        :root {
            --bg-dark: #09090b; --card-dark: #18181b; --border-color: #27272a;
            --text-primary: #f4f4f5; --text-secondary: #a1a1aa;
            --accent-green: #10b981; --accent-red: #ef4444; --accent-purple: #8b5cf6; --accent-orange: #f59e0b;
            color-scheme: dark;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg-dark); color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.5;
        }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 60px; }
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
        .tab {
            padding: 10px 20px; border-radius: 10px; text-decoration: none; border: 1px solid var(--border-color);
            color: var(--text-secondary); font-weight: 700; font-size: 13px; letter-spacing: .04em;
        }
        .tab.active { background: var(--accent-purple); border-color: var(--accent-purple); color: #fff; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 32px 0 10px; }
        .sub { color: var(--text-secondary); margin: 0 0 20px; font-size: 13px; }
        .banner {
            border: 1px solid rgba(245, 158, 11, .35); background: rgba(245, 158, 11, .06); border-radius: 12px;
            padding: 14px 16px; margin-bottom: 24px; font-size: 12px; color: var(--text-secondary); line-height: 1.7;
        }
        .banner strong { color: var(--accent-orange); }
        .scroll { overflow-x: auto; background: var(--card-dark); border: 1px solid var(--border-color); border-radius: 14px; }
        table { border-collapse: collapse; width: 100%; white-space: nowrap; font-size: 13px; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); font-weight: 600; }
        th, td { padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,.05); }
        td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
        tr.control td { background: rgba(139, 92, 246, .06); }
        .up { color: var(--accent-green); } .down { color: var(--accent-red); } .dim { color: var(--text-secondary); }
    </style>
</head>
<body>
@php
    $r = fn ($v) => $v === null ? '—' : sprintf('%+.2fR', $v);
    $tone = fn ($v) => $v === null || $v == 0 ? 'dim' : ($v > 0 ? 'up' : 'down');
    $money = fn ($v) => $v === null ? '—' : '₹' . number_format($v, 2);
@endphp
<div class="wrap">
    <div class="tabs">
        <a class="tab" href="{{ route('screener.index') }}">Intraday</a>
        <a class="tab active" href="{{ route('intraday.practice') }}">Practice</a>
        <a class="tab" href="{{ route('swing.index') }}">Swing</a>
    </div>

    <h1>Intraday Practice</h1>
    <p class="sub">Stocks chosen before the open, followed by the same entry and exit rules every day, after charges.</p>

    <div class="banner">
        <strong>PRACTICE — no money was traded.</strong>
        Both rules lost money when tested on past prices. The random stocks go through the same entry and exit rules, so if the
        picks do no better than them, the picking has no value. <strong>{{ $report['verdict'] }}</strong>
    </div>

    <div class="scroll">
        <table>
            <thead><tr>
                <th>Group</th><th class="n">Trades</th><th class="n">Win rate</th><th class="n">Average</th><th class="n">95% range</th><th class="n">Net ₹</th>
            </tr></thead>
            <tbody>
            @foreach(['signal' => 'Our picks', 'random' => 'Random stocks (comparison)'] as $kind => $name)
                @php $row = $report[$kind]; @endphp
                <tr class="{{ $kind === 'random' ? 'control' : '' }}">
                    <td>{{ $name }}</td>
                    <td class="n">{{ $row['trades'] }}</td>
                    <td class="n">{{ $row['win_rate'] === null ? '—' : $row['win_rate'] . '%' }}</td>
                    <td class="n {{ $tone($row['average_r']) }}">{{ $r($row['average_r']) }}</td>
                    <td class="n dim">{{ $row['range'] ? $r($row['range'][0]) . ' to ' . $r($row['range'][1]) : 'too early' }}</td>
                    <td class="n {{ $tone($row['net_pnl']) }}">{{ number_format($row['net_pnl']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <h2>Today's watchlist</h2>
    <div class="scroll">
        <table>
            <thead><tr><th>#</th><th>Stock</th><th class="n">Last close</th><th>Why</th></tr></thead>
            <tbody>
            @forelse($picks as $p)
                <tr><td>{{ $p->position }}</td><td><strong>{{ $p->symbol }}</strong></td><td class="n">{{ $money($p->close) }}</td><td class="dim">{{ $p->reason() }}</td></tr>
            @empty
                <tr><td colspan="4" class="dim">No stocks picked today.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Latest practice trades</h2>
    <div class="scroll">
        <table>
            <thead><tr>
                <th>Day</th><th>Stock</th><th>Entry time</th><th class="n">Entry</th><th class="n">Stop</th><th class="n">Target</th><th class="n">Qty</th>
                <th>Status</th><th>Exit time</th><th class="n">Exit</th><th class="n">Net ₹</th><th class="n">Result</th>
            </tr></thead>
            <tbody>
            @forelse($trades as $t)
                <tr>
                    <td>{{ $t->day() }}</td>
                    <td><strong>{{ $t->symbol }}</strong></td>
                    <td>{{ $t->price_time }}</td>
                    <td class="n">{{ $money($t->entry) }}</td>
                    <td class="n">{{ $money($t->stop_loss) }}</td>
                    <td class="n">{{ $money($t->target) }}</td>
                    <td class="n">{{ $t->quantity }}</td>
                    <td>{{ $t->status }}</td>
                    <td>{{ $t->exit_time ?? '—' }}</td>
                    <td class="n">{{ $money($t->exit_price) }}</td>
                    <td class="n {{ $tone($t->net_pnl) }}">{{ $t->net_pnl === null ? '—' : number_format($t->net_pnl) }}</td>
                    <td class="n {{ $tone($t->r_multiple) }}">{{ $r($t->r_multiple) }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="dim">No practice trade yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
