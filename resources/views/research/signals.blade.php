<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Research Watch — Gap-and-Hold with Delivery</title>
    @include('research._style')
</head>
<body>
@php
    $f = fn ($v, $d = 2) => $v === '' || $v === null || !is_numeric($v) ? '—' : number_format((float) $v, $d);
    $pct = fn ($v, $d = 1) => $v === '' || $v === null || !is_numeric($v) ? '—' : number_format((float) $v * 100, $d) . '%';
    $tone = fn ($v) => !is_numeric($v) || (float) $v == 0 ? 'dim' : ((float) $v > 0 ? 'up' : 'down');
@endphp
<div class="wrap">
    <div class="tabs">
        <a class="tab" href="{{ route('screener.index') }}">Intraday</a>
        <a class="tab" href="{{ route('swing.index') }}">Swing</a>
        <a class="tab" href="{{ route('swing.paper') }}">Paper log</a>
        <a class="tab" href="{{ route('research.index') }}">Research</a>
        <a class="tab active" href="{{ route('research.signals') }}">Research watch</a>
    </div>

    <h1>{{ $cfg['name'] }} <span class="badge b-weak">{{ $cfg['status'] }}</span></h1>
    <p class="sub">A gap up of 2%+ that holds into a strong close on 2× volume, with delivery % above its own 20-day average.
        Detected from official NSE bhavcopy after the close. Data: {{ $dataDates['first'] ?? '—' }} → {{ $dataDates['last'] ?? '—' }} ({{ $dataDates['sessions'] }} sessions stored).</p>

    <div class="banner">
        <strong>Not a validated strategy and not a recommendation.</strong> In the backtest this pattern was positive in all three
        chronological periods, but it produced only {{ $evidence['Full 2Y']['trades'] ?? '—' }} trades in two years and failed the
        pre-registered 100-trade Development gate. Signals here are paper-tracked to collect forward evidence. Backtest numbers and
        live numbers are shown separately and never mixed.
    </div>

    <h2>Evidence</h2>
    <div class="scroll"><table>
        <tr><th>Source</th><th>Period</th><th class="n">Trades</th><th class="n">Win %</th><th class="n">Avg R</th><th class="n">R 5th–95th pct</th><th class="n">PF</th><th class="n">Avg hold</th></tr>
        @foreach (['Development', 'Validation', 'Test', 'Full 2Y'] as $p)
            @php $e = $evidence[$p] ?? null; @endphp
            @if ($e)
            <tr><td class="dim">Backtest</td><td>{{ $p }}</td><td class="n">{{ $e['trades'] }}</td><td class="n">{{ $pct($e['win_rate']) }}</td>
                <td class="n {{ $tone($e['avg_R']) }}">{{ $f($e['avg_R']) }}</td><td class="n">{{ $f($e['R_lo90']) }} – {{ $f($e['R_hi90']) }}</td>
                <td class="n">{{ $f($e['profit_factor']) }}</td><td class="n">{{ $f($e['avg_bars'], 1) }}</td></tr>
            @endif
        @endforeach
        <tr><td><strong>Live (paper)</strong></td><td>since {{ $live['first_signal'] ?? '—' }}</td><td class="n">{{ $live['closed'] }}</td>
            <td class="n">{{ $pct($live['win_rate']) }}</td><td class="n {{ $tone($live['avg_r']) }}">{{ $f($live['avg_r']) }}</td><td class="n dim">—</td>
            <td class="n">{{ $f($live['profit_factor']) }}</td><td class="n dim">{{ $live['open'] }} open · {{ $live['no_entry'] }} no entry · {{ $live['late'] }} late</td></tr>
    </table></div>

    <h2>Session {{ $scan['date'] ?? '—' }}: {{ count($scan['matches']) }} match(es) of {{ $scan['evaluated'] }} liquid stocks evaluated</h2>
    @if (! $scan['date'])
        <div class="card dim">No NSE bars stored yet. Run <code>php artisan research:scan --backfill=40</code> after 18:00 IST.</div>
    @endif
    @foreach ($scan['matches'] as $m)
        <div class="card">
            <strong style="font-size:16px">{{ $m['symbol'] }}</strong> <span class="dim">close ₹{{ $f($m['close']) }} · data timestamp: NSE bhavcopy {{ $m['date'] }} (end of day)</span>
            <div style="margin:10px 0">
                @foreach ($m['conditions'] as $c)
                    <div class="cond"><span class="mark {{ $c['pass'] ? 'up' : 'down' }}">{{ $c['pass'] ? '✓' : '✗' }}</span><span>{{ $c['label'] }} — <span class="dim">{{ $c['detail'] }}</span></span></div>
                @endforeach
            </div>
            <div class="scroll"><table>
                <tr><th>Entry</th><th class="n">Max entry</th><th class="n">Stop</th><th class="n">Risk</th><th class="n">2R reference</th><th class="n">Qty @ ₹{{ number_format($cfg['capital']) }}, 1% risk</th><th>Exit rule</th></tr>
                <tr><td>{{ $m['plan']['entry'] }}</td><td class="n">₹{{ $f($m['plan']['max_entry']) }}</td><td class="n down">₹{{ $f($m['plan']['stop']) }}</td>
                    <td class="n">{{ $f($m['plan']['risk_percent'], 1) }}%</td><td class="n">₹{{ $f($m['plan']['reference_target']) }} (1:{{ $m['plan']['reward_to_risk'] }})</td>
                    <td class="n">{{ $m['plan']['quantity_for_capital'] }}</td><td style="white-space:normal">{{ $m['plan']['exit_rule'] }}</td></tr>
            </table></div>
        </div>
    @endforeach

    @if ($scan['near_misses'])
        <h2>Near misses (gapped up, failed exactly one condition)</h2>
        <div class="scroll"><table>
            <tr><th>Symbol</th><th>Not satisfied</th><th>Satisfied</th></tr>
            @foreach ($scan['near_misses'] as $m)
                <tr><td>{{ $m['symbol'] }}</td>
                    <td class="down" style="white-space:normal">@foreach ($m['conditions'] as $c) @if (! $c['pass']) {{ $c['label'] }} ({{ $c['detail'] }}) @endif @endforeach</td>
                    <td class="dim" style="white-space:normal">{{ collect($m['conditions'])->where('pass', true)->pluck('label')->implode(' · ') }}</td></tr>
            @endforeach
        </table></div>
    @endif

    <h2>Signal history (live paper tracking)</h2>
    <div class="scroll"><table>
        <tr><th>Signal</th><th>Symbol</th><th>Status</th><th class="n">Entry</th><th class="n">Stop</th><th class="n">Exit</th><th>Reason</th><th class="n">Sessions</th><th class="n">Net %</th><th class="n">R</th><th>Logged</th></tr>
        @forelse ($history as $s)
            <tr><td>{{ $s->signal_date->toDateString() }}</td><td>{{ $s->symbol }}</td><td>{{ $s->status }}{{ $s->no_entry_reason ? ' — ' . $s->no_entry_reason : '' }}</td>
                <td class="n">{{ $f($s->entry_price) }}</td><td class="n">{{ $f($s->current_stop ?? $s->stop_price) }}</td><td class="n">{{ $f($s->exit_price) }}</td>
                <td>{{ $s->exit_reason ?? '—' }}</td><td class="n">{{ $s->bars_held }}</td>
                <td class="n {{ $tone($s->net_pct) }}">{{ $s->net_pct === null ? '—' : $pct($s->net_pct, 2) }}</td><td class="n {{ $tone($s->r_multiple) }}">{{ $f($s->r_multiple) }}</td>
                <td class="dim">{{ $s->late ? 'late (not counted)' : 'on time' }}</td></tr>
        @empty
            <tr><td colspan="11" class="dim">No signals logged yet.</td></tr>
        @endforelse
    </table></div>
</div>
</body>
</html>
