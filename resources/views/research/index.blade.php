<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Swing Research — Backtest Evidence</title>
    @include('research._style')
</head>
<body>
@php
    $f = fn ($v, $d = 2) => $v === '' || $v === null || !is_numeric($v) ? '—' : number_format((float) $v, $d);
    $pct = fn ($v, $d = 1) => $v === '' || $v === null || !is_numeric($v) ? '—' : number_format((float) $v * 100, $d) . '%';
    $tone = fn ($v) => !is_numeric($v) || (float) $v == 0 ? 'dim' : ((float) $v > 0 ? 'up' : 'down');
    $badge = fn ($c) => match (true) {
        str_starts_with((string) $c, 'Strong') => 'b-strong', str_starts_with((string) $c, 'Promising') => 'b-promising',
        str_starts_with((string) $c, 'Weak') => 'b-weak', default => 'b-failed' };
    $q = fn ($over) => route('research.index', array_merge(['period' => $period, 'variant' => $variant, 'window' => $window], $over));
@endphp
<div class="wrap">
    <div class="tabs">
        <a class="tab" href="{{ route('screener.index') }}">Intraday</a>
        <a class="tab" href="{{ route('swing.index') }}">Swing</a>
        <a class="tab" href="{{ route('swing.paper') }}">Paper log</a>
        <a class="tab active" href="{{ route('research.index') }}">Research</a>
        <a class="tab" href="{{ route('research.signals') }}">Research watch</a>
    </div>

    <h1>Swing Research — Historical Backtest Evidence</h1>
    <p class="sub">Official NSE bhavcopy, 26 Nov 2024 – 25 Nov 2026, point-in-time top-500 universe (no survivorship filter),
        next-open entries, delivery charges incl. DP, slippage. Full method: <code>research/FINAL_SWING_TRADING_RESEARCH.md</code>.</p>

    <div class="banner">
        <strong>Backtested, not live.</strong> Every number on this page is a historical simulation. None of the 42 rules tested passed
        every pre-registered gate; nothing here is a validated strategy or a forecast. Development {{ $summary['periods']['Development'][0] ?? '' }} → {{ $summary['periods']['Development'][1] ?? '' }},
        Validation → {{ $summary['periods']['Validation'][1] ?? '' }}, Test → {{ $summary['periods']['Test'][1] ?? '' }}.
    </div>

    @if (! $available)
        <div class="card">Research outputs not found under <code>research/</code>. Run the pipeline in <code>research/README.md</code>.</div>
    @else
    <h2>Ranking (out-of-sample first; win rate is not a ranking criterion)</h2>
    <div class="scroll"><table>
        <tr><th>#</th><th>Strategy</th><th>Class</th><th class="n">OOS trades</th><th class="n">OOS avg R</th><th class="n">OOS PF</th>
            <th>Dev / Val / Test avg R</th><th class="n">₹50k 2Y %</th><th class="n">Max DD %</th><th class="n">avg R @ 2× costs</th></tr>
        @foreach ($ranking as $r)
            <tr><td>{{ $r['rank'] }}</td><td>{{ $r['strategy'] }}</td><td><span class="badge {{ $badge($r['class']) }}">{{ $r['class'] }}</span></td>
                <td class="n">{{ $r['OOS trades'] }}</td><td class="n {{ $tone($r['OOS avg R (Val+Test)']) }}">{{ $f($r['OOS avg R (Val+Test)']) }}</td>
                <td class="n">{{ $f($r['OOS PF']) }}</td><td>{{ $r['Dev/Val/Test avg R'] }}</td>
                <td class="n {{ $tone($r['Rs50k 2Y return %']) }}">{{ $f($r['Rs50k 2Y return %'], 1) }}</td><td class="n">{{ $f($r['Rs50k max DD %'], 1) }}</td>
                <td class="n {{ $tone($r['avg R at 2x costs']) }}">{{ $f($r['avg R at 2x costs']) }}</td></tr>
        @endforeach
    </table></div>

    <h2>Strategy comparison — trade level</h2>
    <div class="filters">
        @foreach ($periods as $p)<a class="{{ $p === $period ? 'on' : '' }}" href="{{ $q(['period' => $p]) }}">{{ $p }}</a>@endforeach
        <span class="dim" style="padding:6px">|</span>
        <a class="{{ $variant === 'base' ? 'on' : '' }}" href="{{ $q(['variant' => 'base']) }}">No market filter</a>
        <a class="{{ $variant === 'M' ? 'on' : '' }}" href="{{ $q(['variant' => 'M']) }}">With market filter</a>
    </div>
    <div class="scroll"><table>
        <tr><th>Strategy</th><th class="n">Trades</th><th class="n">Win %</th><th class="n">Avg win %</th><th class="n">Avg loss %</th>
            <th class="n">Avg R</th><th class="n">PF</th><th class="n">Max DD (R)</th><th class="n">Avg hold</th><th class="n">Edge vs random R</th><th class="n">Edge 5th pct</th><th>Classification</th></tr>
        @foreach ($comparison as $r)
            <tr><td>{{ $r['strategy'] }}</td><td class="n">{{ $r['trades'] }}</td><td class="n">{{ $pct($r['win_rate']) }}</td>
                <td class="n up">{{ $pct($r['avg_win_pct'], 2) }}</td><td class="n down">{{ $pct($r['avg_loss_pct'], 2) }}</td>
                <td class="n {{ $tone($r['avg_R']) }}">{{ $f($r['avg_R']) }}</td><td class="n">{{ $f($r['profit_factor']) }}</td>
                <td class="n">{{ $f($r['max_dd_R_units'], 1) }}</td><td class="n">{{ $f($r['avg_bars'], 1) }}</td>
                <td class="n {{ $tone($r['edge_R']) }}">{{ $f($r['edge_R']) }}</td><td class="n {{ $tone($r['edge_lo90']) }}">{{ $f($r['edge_lo90']) }}</td>
                <td><span class="badge {{ $badge($r['classification']) }}">{{ $r['classification'] }}</span></td></tr>
        @endforeach
    </table></div>

    <h2>₹50,000 portfolio simulation</h2>
    <div class="filters">
        @foreach (['Full 2Y', 'Recent 1Y'] as $w)<a class="{{ $w === $window ? 'on' : '' }}" href="{{ $q(['window' => $w]) }}">{{ $w }}</a>@endforeach
    </div>
    <div class="scroll"><table>
        <tr><th>Strategy</th><th>Sizing</th><th class="n">End ₹</th><th class="n">Net %</th><th class="n">CAGR %</th><th class="n">Max DD %</th>
            <th class="n">Trades</th><th class="n">Win %</th><th class="n">PF</th><th class="n">Sharpe</th><th class="n">Charges ₹</th></tr>
        @foreach (collect($portfolio)->whereIn('sizing', ['Risk 1%, up to 5', 'all-in'])->sortByDesc(fn ($r) => (float) $r['net_return_pct']) as $r)
            <tr><td>{{ $r['strategy'] }}</td><td class="dim">{{ $r['sizing'] }}</td><td class="n">{{ $f($r['end_capital'], 0) }}</td>
                <td class="n {{ $tone($r['net_return_pct']) }}">{{ $f($r['net_return_pct'], 1) }}</td><td class="n">{{ $f($r['cagr_pct'], 1) }}</td>
                <td class="n">{{ $f($r['max_drawdown_pct'], 1) }}</td><td class="n">{{ $r['trades'] }}</td><td class="n">{{ $pct($r['win_rate']) }}</td>
                <td class="n">{{ $f($r['profit_factor']) }}</td><td class="n">{{ $f($r['sharpe']) }}</td><td class="n">{{ $f($r['total_charges'], 0) }}</td></tr>
        @endforeach
    </table></div>

    <h2>Charts (real NSE data)</h2>
    <div class="grid">
        @foreach ($charts as $c)
            <a href="{{ route('research.chart', $c) }}" target="_blank"><img loading="lazy" src="{{ route('research.chart', $c) }}" alt="{{ $c }}"></a>
        @endforeach
    </div>
    @endif
</div>
</body>
</html>
