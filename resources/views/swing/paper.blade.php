<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Paper Log — Swing</title>
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
        <a class="tab" href="{{ route('swing.index') }}">Swing</a>
        <a class="tab active" href="{{ route('swing.paper') }}">Paper log</a>
    </div>

    <h1>Paper Log</h1>
    <p class="sub">Every READY plan, written down before its result could be known, then closed by the backtest's rules after charges.</p>

    <div class="banner">
        <strong>{{ $report['label'] }}.</strong>
        Compare each row with the random entries at the bottom: they use the same stop, target and holding limit, so a
        strategy that does no better than them has no edge. R is profit as a multiple of the money risked. The range
        appears once there are {{ config('swing.paper.min_weeks') }} separate weeks of results; until then the averages
        are mostly luck.
        @if($report['late'])
            {{ $report['late'] }} plan(s) were logged after their session had opened and are not counted. Scan after the close to avoid this.
        @endif
    </div>

    @if($b = $report['benchmark'])
        <div class="banner" style="border-color: rgba(139, 92, 246, .35); background: rgba(139, 92, 246, .06);">
            <strong style="color: var(--text-primary)">Against buying the index.</strong>
            Since {{ \Illuminate\Support\Carbon::parse($b['since'])->format('d M Y') }}:
            paper account <b class="{{ $tone($b['account_percent']) }}">{{ sprintf('%+.2f%%', $b['account_percent']) }}</b>
            of ₹{{ number_format(config('swing.costs.capital')) }} from {{ $b['closed'] }} closed {{ \Illuminate\Support\Str::plural('trade', $b['closed']) }},
            NIFTY 50 (what NIFTYBEES follows) <b class="{{ $tone($b['nifty_percent']) }}">{{ sprintf('%+.2f%%', $b['nifty_percent']) }}</b>
            to {{ \Illuminate\Support\Carbon::parse($b['until'])->format('d M Y') }}.
            {{ $b['account_percent'] > $b['nifty_percent'] ? 'Ahead of the index so far.' : 'Behind the index: just holding NIFTYBEES did better.' }}
            Open positions are not counted, and the index figure has no charges or dividends.
        </div>
    @endif

    <div class="scroll">
        <table>
            <thead><tr>
                <th>Group</th><th class="n">Closed</th><th class="n">Win rate</th><th class="n">Average</th><th class="n">95% range</th>
                <th class="n">Profit factor</th><th class="n">Total</th><th class="n">Net ₹</th><th class="n">Open</th><th class="n">Waiting</th><th class="n">Not entered</th>
            </tr></thead>
            <tbody>
            @foreach($report['rows'] as $row)
                <tr class="{{ $loop->last ? 'control' : '' }}">
                    <td>{{ $row['name'] }}</td>
                    <td class="n">{{ $row['closed'] }}</td>
                    <td class="n">{{ $row['win_rate'] === null ? '—' : $row['win_rate'] . '%' }}</td>
                    <td class="n {{ $tone($row['average_r']) }}">{{ $r($row['average_r']) }}</td>
                    <td class="n dim">{{ $row['range'] ? $r($row['range'][0]) . ' to ' . $r($row['range'][1]) : 'too early' }}</td>
                    <td class="n">{{ $row['profit_factor'] ?? '—' }}</td>
                    <td class="n {{ $tone($row['total_r']) }}">{{ $r($row['total_r']) }}</td>
                    <td class="n {{ $tone($row['net_pnl']) }}">{{ number_format($row['net_pnl']) }}</td>
                    <td class="n">{{ $row['open'] }}</td>
                    <td class="n">{{ $row['waiting'] }}</td>
                    <td class="n">{{ $row['not_entered'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <h2>Latest plans</h2>
    <div class="scroll">
        <table>
            <thead><tr>
                <th>Signal</th><th>Stock</th><th>Strategy</th><th>Paper account</th><th class="n">Entry</th><th class="n">Max</th><th class="n">Stop</th>
                <th class="n">Target</th><th>Status</th><th class="n">Filled</th><th class="n">Exit</th><th>Reason</th><th class="n">Days</th><th class="n">Result</th>
            </tr></thead>
            <tbody>
            @forelse($trades as $t)
                <tr>
                    <td>{{ $t->signal_date->format('d M Y') }}{{ $t->late ? ' (late, not counted)' : '' }}</td>
                    <td><strong>{{ $t->symbol }}</strong></td>
                    <td>{{ $t->strategy }}</td>
                    <td class="dim">{{ $t->portfolio === null ? '—' : $t->portfolio_reason }}</td>
                    <td class="n">{{ $money($t->entry_trigger ?? $t->signal_price) }}</td>
                    <td class="n">{{ $money($t->max_entry) }}</td>
                    <td class="n">{{ $money($t->stop_price) }}</td>
                    <td class="n">{{ $money($t->target1) }}</td>
                    <td>{{ str_replace('_', ' ', $t->status) }}</td>
                    <td class="n">{{ $money($t->entry_price) }}</td>
                    <td class="n">{{ $money($t->exit_price) }}</td>
                    <td class="dim">{{ str_replace('_', ' ', $t->exit_reason ?? '—') }}</td>
                    <td class="n">{{ $t->bars_held ?? '—' }}</td>
                    <td class="n {{ $tone($t->r_multiple) }}">{{ $r($t->r_multiple) }}</td>
                </tr>
            @empty
                <tr><td colspan="14" class="dim">Nothing logged yet. The next full swing scan writes the first plans.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
