<style>
    :root {
        --bg-dark: #09090b; --card-dark: #18181b; --border-color: #27272a;
        --text-primary: #f4f4f5; --text-secondary: #a1a1aa;
        --accent-green: #10b981; --accent-red: #ef4444; --accent-purple: #8b5cf6; --accent-orange: #f59e0b; --accent-blue: #3b82f6;
        color-scheme: dark;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg-dark); color: var(--text-primary);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; line-height: 1.5; }
    .wrap { max-width: 1400px; margin: 0 auto; padding: 24px 16px 60px; }
    .tabs { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
    .tab { padding: 10px 20px; border-radius: 10px; text-decoration: none; border: 1px solid var(--border-color);
        color: var(--text-secondary); font-weight: 700; font-size: 13px; letter-spacing: .04em; }
    .tab.active { background: var(--accent-purple); border-color: var(--accent-purple); color: #fff; }
    h1 { font-size: 26px; margin: 0 0 4px; } h2 { font-size: 15px; margin: 32px 0 10px; }
    .sub { color: var(--text-secondary); margin: 0 0 20px; font-size: 13px; }
    .banner { border: 1px solid rgba(245,158,11,.35); background: rgba(245,158,11,.06); border-radius: 12px;
        padding: 14px 16px; margin-bottom: 24px; font-size: 12.5px; color: var(--text-secondary); line-height: 1.7; }
    .banner strong { color: var(--accent-orange); }
    .scroll { overflow-x: auto; background: var(--card-dark); border: 1px solid var(--border-color); border-radius: 14px; }
    table { border-collapse: collapse; width: 100%; white-space: nowrap; font-size: 13px; }
    th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); font-weight: 600; }
    th, td { padding: 9px 12px; border-bottom: 1px solid rgba(255,255,255,.05); }
    td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
    .up { color: var(--accent-green); } .down { color: var(--accent-red); } .dim { color: var(--text-secondary); }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; border: 1px solid var(--border-color); }
    .b-strong { color: var(--accent-green); border-color: rgba(16,185,129,.5); }
    .b-promising { color: var(--accent-blue); border-color: rgba(59,130,246,.5); }
    .b-weak { color: var(--accent-orange); border-color: rgba(245,158,11,.5); }
    .b-failed { color: var(--accent-red); border-color: rgba(239,68,68,.5); }
    .filters { display: flex; gap: 8px; flex-wrap: wrap; margin: 8px 0 12px; }
    .filters a { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--border-color); color: var(--text-secondary); text-decoration: none; font-size: 12px; }
    .filters a.on { border-color: var(--accent-purple); color: #fff; background: rgba(139,92,246,.2); }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 14px; }
    .grid img { width: 100%; border-radius: 10px; border: 1px solid var(--border-color); background: #fcfcfb; }
    .card { background: var(--card-dark); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; margin-bottom: 14px; }
    .cond { display: flex; gap: 8px; font-size: 13px; padding: 3px 0; }
    .cond .mark { width: 18px; font-weight: 800; }
    @media (max-width: 520px) { .grid { grid-template-columns: 1fr; } }
</style>
