<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Swing Setups — Multi-day</title>
    <style>
        :root {
            --bg-dark: #09090b;
            --card-dark: #18181b;
            --border-color: #27272a;
            --text-primary: #f4f4f5;
            --text-secondary: #a1a1aa;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --accent-orange: #f59e0b;
            --glow-green: rgba(16, 185, 129, 0.15);
            --glow-red: rgba(239, 68, 68, 0.15);
            color-scheme: dark;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg-dark); color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 14px; line-height: 1.5;
        }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 60px; }

        /* Which system you are looking at has to be unmissable: the two have different
           holding periods, different costs and different backtests. */
        .tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
        .tab {
            padding: 10px 20px; border-radius: 10px; text-decoration: none;
            border: 1px solid var(--border-color); color: var(--text-secondary);
            font-weight: 700; font-size: 13px; letter-spacing: .04em;
        }
        .tab.active { background: var(--accent-purple); border-color: var(--accent-purple); color: #fff; }

        h1 { font-size: 26px; margin: 0 0 4px; }
        .sub { color: var(--text-secondary); margin: 0 0 20px; font-size: 13px; }

        .banner {
            border: 1px solid rgba(245, 158, 11, .35); background: rgba(245, 158, 11, .06);
            border-radius: 12px; padding: 14px 16px; margin-bottom: 24px;
            font-size: 12px; color: var(--text-secondary); line-height: 1.7;
        }
        .banner strong { color: var(--accent-orange); }

        .regime { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 28px; }
        .regime div { background: var(--card-dark); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 16px; }
        .regime span { display: block; font-size: 10px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .08em; }
        .regime b { font-size: 17px; font-weight: 700; }

        h2 { font-size: 15px; margin: 32px 0 4px; display: flex; align-items: center; gap: 10px; }
        h2 small { font-weight: 400; color: var(--text-secondary); font-size: 12px; }

        .card {
            background: var(--card-dark); border: 1px solid var(--border-color);
            border-radius: 14px; padding: 18px; margin-top: 12px;
        }
        .card.qualifies { border-color: rgba(16, 185, 129, .4); }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
        .sym { font-size: 19px; font-weight: 800; letter-spacing: .02em; }
        .score { font-size: 22px; font-weight: 800; }
        .score small { font-size: 11px; font-weight: 400; color: var(--text-secondary); }

        .badges { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0 0; }
        .badge {
            font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
            text-transform: uppercase; letter-spacing: .05em;
            background: rgba(139, 92, 246, .12); color: var(--accent-purple);
            border: 1px solid rgba(139, 92, 246, .25);
        }
        .badge.warn { background: rgba(245,158,11,.1); color: var(--accent-orange); border-color: rgba(245,158,11,.25); }
        .badge.ok { background: var(--glow-green); color: var(--accent-green); border-color: rgba(16,185,129,.3); }

        .levels { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 14px; margin-top: 16px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.05); }
        .levels div span { display: block; font-size: 10px; color: var(--text-secondary); }
        .levels div b { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 16px; font-weight: 600; }

        .checks { margin-top: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 6px; }
        .checks div { font-size: 12px; color: var(--text-secondary); display: flex; gap: 7px; }
        .checks .y { color: var(--accent-green); font-weight: 700; }
        .checks .n { color: var(--accent-red); font-weight: 700; }

        .watch { margin-top: 12px; font-size: 13px; color: var(--accent-orange); }
        .empty { color: var(--text-secondary); font-size: 13px; padding: 18px 0; }
        code { background: rgba(255,255,255,.06); padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrap">

    <div class="tabs">
        <a class="tab" href="{{ route('screener.index') }}">Intraday</a>
        <a class="tab active" href="{{ route('swing.index') }}">Swing</a>
    </div>

    <h1>Swing Setups</h1>
    <p class="sub">
        Multi-day setups from daily candles.
        @if($scanDate)
            Scan of {{ \Illuminate\Support\Carbon::parse($scanDate)->format('D, d M Y') }}.
        @endif
    </p>

    {{-- Stated once, prominently, on every view. Nothing here has been validated, and a
         page that looks authoritative without saying so is how the intraday win rates
         came to be trusted for months. --}}
    <div class="banner">
        <strong>NOT TESTED.</strong>
        No swing strategy here has been validated out of sample. Every threshold and every scoring weight is a
        conventional starting value, not a finding. The score ranks setups against each other — it is not a
        probability of profit, and entry, stop and target describe a plan rather than a prediction.
        Signals come from the last completed daily close; the entry is the next session.
    </div>

    {{-- Check one stock on demand, without waiting for the nightly scan --}}
    <div class="card" style="margin-bottom: 8px;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <input id="q" type="text" placeholder="Check any stock for a swing setup (RELIANCE, TITAN, SBIN...)"
                   autocomplete="off" spellcheck="false"
                   style="flex: 1; min-width: 220px; padding: 12px 16px; border-radius: 10px; font-size: 14px;
                          background: var(--bg-dark); border: 1px solid var(--border-color); color: var(--text-primary);">
            <button id="go"
                    style="padding: 12px 24px; border-radius: 10px; border: 0; cursor: pointer;
                           background: var(--accent-purple); color: #fff; font-weight: 700; font-size: 13px;">
                Check setup
            </button>
        </div>
    </div>

    <div id="result"></div>

    @if(! $scanDate)
        <div class="card">
            <div class="empty">
                No swing scan has run yet. Run <code>php artisan swing:scan</code>, or
                <code>/api/run-swing?token=...</code> in production.
            </div>
        </div>
    @else
        <div class="regime">
            <div>
                <span>Market trend (NIFTY)</span>
                <b style="color: {{ match($regime?->regime_trend) { 'BULLISH_TREND' => 'var(--accent-green)', 'BEARISH_TREND' => 'var(--accent-red)', default => 'var(--text-primary)' } }}">
                    {{ str_replace('_', ' ', $regime?->regime_trend ?? 'UNKNOWN') }}
                </b>
            </div>
            <div>
                <span>Market volatility</span>
                <b>{{ str_replace('_', ' ', $regime?->regime_volatility ?? 'UNKNOWN') }}</b>
            </div>
            <div>
                <span>Sector strength</span>
                <b style="color: var(--text-secondary)">NOT AVAILABLE</b>
            </div>
            <div>
                <span>Market breadth</span>
                <b style="color: var(--text-secondary)">NOT AVAILABLE</b>
            </div>
        </div>

        @foreach([
            ['Ready now', $ready, 'Every condition met. The entry is a resting order for the next session.', 'ok'],
            ['Watchlist', $watchlist, 'Close to triggering. Nothing to do yet — the setup has not proved itself.', 'warn'],
            ['Extended', $extended, 'The setup was valid, but price has already run. Entering now takes a different risk to the plan.', 'warn'],
        ] as [$title, $group, $blurb, $tone])
            <h2>{{ $title }} <small>{{ $group->count() }} {{ \Illuminate\Support\Str::plural('stock', $group->count()) }} — {{ $blurb }}</small></h2>

            @forelse($group as $stock)
                <div class="card {{ $stock->qualifies && $tone === 'ok' ? 'qualifies' : '' }}">
                    <div class="head">
                        <div>
                            <div class="sym">{{ $stock->symbol }}</div>
                            <div class="badges">
                                @foreach($stock->signals as $signal)
                                    <span class="badge">{{ $signal->strategy }}</span>
                                @endforeach

                                {{-- Independent evidence, not a headcount: strategies reading the
                                     same information are one piece of evidence, not several. --}}
                                @if($stock->strategies > $stock->families)
                                    <span class="badge warn">{{ $stock->strategies }} strategies, {{ $stock->families }} independent</span>
                                @elseif($stock->qualifies)
                                    <span class="badge ok">{{ $stock->families }} independent families</span>
                                @endif
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div class="score">{{ $stock->score }}<small>/100</small></div>
                            <small style="color: var(--text-secondary); font-size: 11px;">{{ $stock->best->holding_estimate }}</small>
                        </div>
                    </div>

                    @if($stock->best->state === 'READY' && $stock->best->stop_price)
                        <div class="levels">
                            <div>
                                <span>Entry (trigger)</span>
                                <b>₹{{ number_format($stock->best->entryLevel(), 2) }}</b>
                            </div>
                            <div>
                                <span>Stop — {{ str_replace('_', ' ', $stock->best->stop_method) }}</span>
                                <b style="color: var(--accent-red)">₹{{ number_format($stock->best->stop_price, 2) }}</b>
                            </div>
                            <div>
                                <span>Target 1 · {{ number_format($stock->best->target1_r, 1) }}R</span>
                                <b style="color: var(--accent-green)">₹{{ number_format($stock->best->target1, 2) }}</b>
                            </div>
                            <div>
                                <span>Target 2 · {{ number_format($stock->best->target2_r, 1) }}R</span>
                                <b style="color: var(--accent-green)">₹{{ number_format($stock->best->target2, 2) }}</b>
                            </div>
                            <div>
                                <span>Risk per share</span>
                                <b>₹{{ number_format($stock->best->riskPerShare(), 2) }} ({{ $stock->best->riskPercent() }}%)</b>
                            </div>
                            <div>
                                <span>Signal close</span>
                                <b style="color: var(--text-secondary)">₹{{ number_format($stock->best->signal_price, 2) }}</b>
                            </div>
                        </div>
                    @endif

                    @if($stock->best->watch_for)
                        <div class="watch">⏳ {{ $stock->best->watch_for }}</div>
                    @endif

                    <div class="checks">
                        @foreach($stock->best->checks ?? [] as $check)
                            <div>
                                <span class="{{ $check['pass'] ? 'y' : 'n' }}">{{ $check['pass'] ? '✓' : '✗' }}</span>
                                <span><strong style="color: var(--text-primary)">{{ $check['label'] }}</strong> — {{ $check['detail'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="card"><div class="empty">Nothing in this group today.</div></div>
            @endforelse
        @endforeach
    @endif

</div>

<script>
    const VERDICTS = {
        SETUP:      { icon: '✅', colour: 'var(--accent-green)',  border: 'rgba(16,185,129,.4)'  },
        SETUP_THIN: { icon: '⚠️', colour: 'var(--accent-orange)', border: 'rgba(245,158,11,.4)'  },
        TOO_LATE:   { icon: '⏭️', colour: 'var(--accent-orange)', border: 'rgba(245,158,11,.4)'  },
        WAIT:       { icon: '⏳', colour: 'var(--accent-orange)', border: 'rgba(245,158,11,.35)' },
        NO_SETUP:   { icon: '—',  colour: 'var(--text-secondary)', border: 'var(--border-color)' },
    };

    const money = n => '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function planRows(plan) {
        if (!plan) return '';

        return `<div class="levels">
            <div><span>Entry ${plan.entry_is_trigger ? '(break above)' : '(next open)'}</span><b>${money(plan.entry)}</b></div>
            <div><span>Stop — ${plan.stop_method.replace(/_/g, ' ')}</span><b style="color:var(--accent-red)">${money(plan.stop)}</b></div>
            <div><span>Target 1 · ${plan.target1_r}R</span><b style="color:var(--accent-green)">${money(plan.target1)}</b></div>
            ${plan.target2 ? `<div><span>Target 2 · ${plan.target2_r}R</span><b style="color:var(--accent-green)">${money(plan.target2)}</b></div>` : ''}
            <div><span>Risk per share</span><b>${money(plan.risk_per_share)} (${plan.risk_percent}%)</b></div>
        </div>`;
    }

    function strategyCard(s) {
        const v = VERDICTS[s.state] || VERDICTS.NO_SETUP;
        const checks = (s.checks || []).map(c => `
            <div><span class="${c.pass ? 'y' : 'n'}">${c.pass ? '✓' : '✗'}</span>
            <span><strong style="color:var(--text-primary)">${c.label}</strong> — ${c.detail}</span></div>`).join('');

        return `<div class="card" style="border-color:${s.state === 'READY' ? 'rgba(16,185,129,.35)' : 'var(--border-color)'}">
            <div class="head">
                <div>
                    <div class="sym" style="font-size:16px">${s.strategy}</div>
                    <div class="badges"><span class="badge">${s.family.replace(/_/g, ' ')}</span>
                    <span class="badge ${s.state === 'READY' ? 'ok' : 'warn'}">${s.state.replace(/_/g, ' ')}</span></div>
                </div>
                ${s.plan ? `<div class="score">${s.score}<small>/100</small></div>` : ''}
            </div>
            ${planRows(s.plan)}
            ${s.watch_for ? `<div class="watch">⏳ ${s.watch_for}</div>` : ''}
            <div class="checks">${checks}</div>
        </div>`;
    }

    async function check() {
        const symbol = document.getElementById('q').value.trim().toUpperCase();
        const out = document.getElementById('result');

        if (!symbol) return;

        out.innerHTML = '<div class="card"><div class="empty">Checking ' + symbol + '…</div></div>';

        try {
            const res = await fetch('{{ route('swing.analyze') }}?symbol=' + encodeURIComponent(symbol));
            const d = await res.json();

            if (!res.ok) {
                out.innerHTML = `<div class="card"><div style="color:var(--accent-red)">${d.error}</div></div>`;
                return;
            }

            const v = VERDICTS[d.verdict.code] || VERDICTS.NO_SETUP;

            // The gap between the close the plan was built on and the price right now is
            // the thing most easily misread, so it is stated rather than implied.
            const drift = d.current_price
                ? `Signal close ${money(d.signal_close)} on ${d.as_of} · trading at ${money(d.current_price)} now`
                : `Signal close ${money(d.signal_close)} on ${d.as_of}`;

            out.innerHTML = `
                <div class="card" style="border-color:${v.border}">
                    <div class="head">
                        <div>
                            <div class="sym">${d.symbol}</div>
                            <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">${drift}</div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-size:19px;font-weight:800;color:${v.colour}">${v.icon} ${d.verdict.headline}</div>
                            <div style="color:var(--text-secondary);font-size:12px;margin-top:4px">${d.verdict.detail}</div>
                            <div class="score" style="margin-top:8px">${d.condition_score}<small>/100 stock condition</small></div>
                        </div>
                    </div>
                    <div class="levels">
                        <div><span>Market (NIFTY)</span><b style="font-size:14px">${(d.regime.trend || 'UNKNOWN').replace(/_/g, ' ')}</b></div>
                        <div><span>Volatility</span><b style="font-size:14px">${(d.regime.volatility || 'UNKNOWN').replace(/_/g, ' ')}</b></div>
                        <div><span>Weekly trend</span><b style="font-size:14px">${d.weekly_trend}</b></div>
                        <div><span>vs NIFTY 20/50/100d</span><b style="font-size:14px">${
                            [20, 50, 100].map(p => d.relative_strength.periods[p] === null ? 'n/a'
                                : (d.relative_strength.periods[p] > 0 ? '+' : '') + d.relative_strength.periods[p] + '%').join(' / ')
                        }</b></div>
                        <div><span>Independent evidence</span><b style="font-size:14px">${d.agreement.families} ${d.agreement.families === 1 ? 'family' : 'families'}</b></div>
                    </div>
                    <div style="margin-top:12px;font-size:12px;color:var(--text-secondary)">${d.agreement.summary}</div>
                </div>
                <h2>What each strategy says <small>7 strategies, on the close of ${d.as_of}. A score appears only where there is a plan to score.</small></h2>
                ${d.strategies.sort((a, b) => b.score - a.score).map(strategyCard).join('')}
            `;
        } catch (e) {
            out.innerHTML = '<div class="card"><div style="color:var(--accent-red)">Could not reach the server. Try again in a moment.</div></div>';
        }
    }

    document.getElementById('go').addEventListener('click', check);
    document.getElementById('q').addEventListener('keydown', e => e.key === 'Enter' && check());
</script>
</body>
</html>
