<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morning Intraday Screener</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
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
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            padding: 40px 20px;
            line-height: 1.5;
            overflow-x: hidden;
        }

        .container {
            max-width: 1250px;
            margin: 0 auto;
        }

        /* Header section with gradient glow */
        header {
            position: relative;
            margin-bottom: 30px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            flex-wrap: wrap;
            gap: 20px;
        }

        .logo-area h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #ffffff 30%, #a1a1aa 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }

        .logo-area p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .scan-time {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 9999px;
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .scan-indicator {
            width: 8px;
            height: 8px;
            background-color: var(--accent-green);
            border-radius: 50%;
            box-shadow: 0 0 8px var(--accent-green);
            display: inline-block;
        }

        /* Search Section */
        .search-section {
            background: var(--card-dark);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box-container {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-input {
            width: 100%;
            background: #09090b;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            transition: border-color 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .search-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .search-btn:hover {
            background: #2563eb;
        }

        /* Deep Analysis Result Card */
        .analysis-card {
            background: var(--card-dark);
            border: 1px solid #3b82f6;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 10px 40px rgba(59, 130, 246, 0.15);
            display: none; /* hidden initially */
            position: relative;
        }

        .close-analysis-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
            transition: all 0.2s ease;
        }

        .close-analysis-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
        }

        .analysis-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 24px;
        }

        .analysis-title-group h2 {
            font-size: 26px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .analysis-title-group p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 4px;
        }

        .analysis-price-group {
            text-align: right;
        }

        .analysis-price {
            font-size: 28px;
            font-weight: 800;
            font-family: monospace;
        }

        .analysis-change {
            font-size: 14px;
            font-weight: 700;
            margin-top: 2px;
        }

        /* Health Bias Badge */
        .bias-badge {
            display: inline-block;
            font-size: 13px;
            font-weight: 800;
            padding: 6px 16px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 8px;
        }
        .bias-bullish { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.3); }
        .bias-bearish { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.3); }
        .bias-neutral { background: rgba(255, 255, 255, 0.05); color: var(--text-secondary); border: 1px solid var(--border-color); }

        /* Multi-Strategy Setups Section */
        .analysis-setups-section {
            background: rgba(255, 255, 255, 0.01);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 30px;
        }

        .analysis-setups-section h3 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-primary);
        }

        .setup-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
        }

        .analysis-setup-card {
            background: #111;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            position: relative;
        }

        .analysis-setup-card.buy { border-left: 4px solid var(--accent-green); }
        .analysis-setup-card.sell { border-left: 4px solid var(--accent-red); }

        /* Indicators Grid */
        .indicators-grid-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .analysis-indicators-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .indicator-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }

        .indicator-card h4 {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .indicator-card .value {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .indicator-card .status {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .indicator-card .detail {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 6px;
            line-height: 1.4;
        }

        /* News & Quarter Results Section */
        .analysis-news-section {
            border-top: 1px solid var(--border-color);
            padding-top: 30px;
        }

        .news-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .news-header h3 {
            font-size: 16px;
            font-weight: 700;
        }

        .results-badge {
            background: rgba(245, 158, 11, 0.15);
            color: var(--accent-orange);
            border: 1px solid rgba(245, 158, 11, 0.3);
            font-size: 11px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 8px;
            text-transform: uppercase;
        }

        .news-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .news-item {
            background: rgba(255, 255, 255, 0.01);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            transition: background-color 0.2s ease;
        }

        .news-item:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .news-info {
            flex: 1;
            min-width: 250px;
        }

        .news-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            text-decoration: none;
            line-height: 1.4;
        }

        .news-title:hover {
            color: #3b82f6;
        }

        .news-meta {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 6px;
        }

        .quarter-tag {
            background: rgba(59, 130, 246, 0.15);
            color: var(--accent-blue);
            border: 1px solid rgba(59, 130, 246, 0.3);
            font-size: 10px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-dark);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            border-color: #3f3f46;
        }

        .stat-card.total::after { background: var(--accent-blue); }
        .stat-card.buys::after { background: var(--accent-green); }
        .stat-card.sells::after { background: var(--accent-red); }

        .stat-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
        }

        /* Two-Column Layout */
        .main-layout {
            display: flex;
            gap: 30px;
            align-items: flex-start;
            margin-bottom: 40px;
        }

        .left-col {
            flex: 3;
            min-width: 0; /* fixes flexbox table overflow */
        }

        .right-col {
            flex: 1;
            min-width: 290px;
            position: sticky;
            top: 20px;
        }

        /* Earnings Calendar Card */
        .calendar-card {
            background: var(--card-dark);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .calendar-card h3 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-primary);
        }

        .calendar-section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--text-secondary);
            letter-spacing: 0.5px;
            margin-top: 20px;
            margin-bottom: 12px;
            border-bottom: 1px solid #27272a;
            padding-bottom: 4px;
        }

        .calendar-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .calendar-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.01);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            border-radius: 8px;
            transition: border-color 0.2s ease;
        }

        .calendar-item:hover {
            border-color: #3f3f46;
        }

        .calendar-item-info {
            display: flex;
            align-items: center;
            gap: 8px;
            overflow: hidden;
        }

        .calendar-symbol {
            font-size: 11px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.05);
            padding: 4px 8px;
            border-radius: 6px;
            color: var(--text-primary);
        }

        .calendar-name {
            font-size: 12px;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 110px;
        }

        .calendar-action-btn {
            background: transparent;
            border: 1px solid rgba(59, 130, 246, 0.4);
            color: var(--accent-blue);
            font-size: 11px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .calendar-action-btn:hover {
            background: var(--accent-blue);
            color: white;
        }

        /* Filters/Tabs */
        .filter-container {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            overflow-x: auto;
            padding-bottom: 8px;
        }

        .filter-btn {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-primary);
        }

        .filter-btn.active {
            background: var(--text-primary);
            color: var(--bg-dark);
            border-color: var(--text-primary);
        }

        /* Table Card */
        .table-card {
            background: var(--card-dark);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            margin-bottom: 40px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background: rgba(255, 255, 255, 0.01);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-secondary);
        }

        tr {
            transition: background-color 0.2s ease;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: rgba(255, 255, 255, 0.015);
        }

        .symbol-badge {
            font-size: 16px;
            font-weight: 700;
            color: #ffffff;
        }

        .symbol-ns {
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 400;
        }

        .strategy-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 8px;
            letter-spacing: 0.5px;
        }

        .strat-ma { background: rgba(59, 130, 246, 0.1); color: var(--accent-blue); border: 1px solid rgba(59, 130, 246, 0.2); }
        .strat-rsi { background: rgba(139, 92, 246, 0.1); color: var(--accent-purple); border: 1px solid rgba(139, 92, 246, 0.2); }
        .strat-bb { background: rgba(245, 158, 11, 0.1); color: var(--accent-orange); border: 1px solid rgba(245, 158, 11, 0.2); }
        .strat-macd { background: rgba(236, 72, 153, 0.1); color: #ec4899; border: 1px solid rgba(236, 72, 153, 0.2); }
        .strat-vol { background: rgba(16, 185, 129, 0.1); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.2); }
        .strat-earnings { background: rgba(234, 179, 8, 0.1); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.2); }
        .strat-orb { background: rgba(244, 63, 94, 0.1); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.2); }
        .strat-high-momentum { background: rgba(245, 158, 11, 0.1); color: var(--accent-orange); border: 1px solid rgba(245, 158, 11, 0.2); }

        .signal-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 800;
            padding: 6px 12px;
            border-radius: 30px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .signal-pill.buy {
            background: var(--glow-green);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .signal-pill.sell {
            background: var(--glow-red);
            color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .volume-badge {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            font-size: 10px;
            color: var(--text-secondary);
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: middle;
        }

        .price-val {
            font-family: monospace;
            font-size: 15px;
            font-weight: 600;
        }

        .live-price-container {
            display: flex;
            flex-direction: column;
        }

        .live-percent {
            font-size: 11px;
            font-weight: 700;
            margin-top: 2px;
        }

        .live-percent.profit { color: var(--accent-green); }
        .live-percent.loss { color: var(--accent-red); }

        .status-pill {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .status-pill.active { background: rgba(255, 255, 255, 0.05); color: var(--text-primary); }
        .status-pill.sl { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); border: 1px solid rgba(239, 68, 68, 0.3); }
        .status-pill.target { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.3); }

        .reason-text {
            font-size: 13px;
            color: var(--text-secondary);
            max-width: 280px;
        }

        .empty-state {
            padding: 80px 40px;
            text-align: center;
            color: var(--text-secondary);
        }

        .empty-state h3 {
            color: var(--text-primary);
            font-size: 18px;
            margin-bottom: 8px;
        }

        /* Strategy Guide Grid */
        .guide-section {
            margin-top: 60px;
        }

        .guide-section h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 24px;
            letter-spacing: -0.3px;
        }

        .guide-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 24px;
        }

        .guide-card {
            background: var(--card-dark);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .guide-card h3 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .guide-card p {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .guide-card .badge {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 800;
        }

        .disclaimer {
            margin-top: 60px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
            font-size: 12px;
            color: #52525b;
            line-height: 1.8;
            text-align: center;
        }

        @media (max-width: 992px) {
            .main-layout {
                flex-direction: column;
            }
            .right-col {
                width: 100%;
                position: static;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <header>
        <div class="header-content">
            <div class="logo-area">
                <h1>Morning Intraday Screener</h1>
                <p>Advanced algorithmic setup analysis for active traders</p>
            </div>
            <div class="scan-time">
                <span class="scan-indicator"></span>
                <span>{{ now()->format('l, d M Y') }} @if($lastScanAt) — Scanned at {{ $lastScanAt->format('h:i A') }} @endif</span>
            </div>
        </div>
    </header>

    <!-- Search Engine -->
    <div class="search-section">
        <div class="search-box-container">
            <input type="text" id="searchInput" class="search-input" placeholder="Search any stock (e.g. SBIN, RELIANCE, TATASTEEL)...">
        </div>
        <button class="search-btn" onclick="analyzeStock()">Perform Deep Analysis</button>
    </div>

    <!-- Deep Analysis Card -->
    <div class="analysis-card" id="analysisReportCard">
        <button class="close-analysis-btn" onclick="closeAnalysis()">×</button>
        
        <div class="analysis-header">
            <div class="analysis-title-group">
                <h2 id="analysisSymbol">SBIN</h2>
                <div id="analysisBiasBadge"></div>
                <p>Live Indicator run & corporate news sentiment analysis</p>
            </div>
            <div class="analysis-price-group">
                <div class="analysis-price" id="analysisPrice">₹590.20</div>
                <div class="analysis-change" id="analysisChange">+1.2%</div>
            </div>
        </div>

        <!-- Live ORB + VWAP Setup Board -->
        <div class="intraday-setup-section" id="orbSetupContainer" style="display: none; margin-bottom: 30px; border-radius: 16px; border: 1px dashed rgba(244, 63, 94, 0.4); padding: 24px; background: rgba(244, 63, 94, 0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <h3 style="font-size: 16px; font-weight: 800; color: #f43f5e; display: flex; align-items: center; gap: 8px;">
                    🎯 Live 15m Opening Range Breakout (ORB) + VWAP
                </h3>
                <span class="decision-badge" id="orbDecisionBadge" style="font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 20px; text-transform: uppercase;">ACTIVE</span>
            </div>
            
            <p id="orbReason" style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.6;">Reasoning...</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 10px;">
                    <div style="font-size: 10px; color: var(--text-secondary);">ORB High (15m)</div>
                    <div class="price-val" id="orbHighVal" style="font-size: 16px; color: white;">₹0.00</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 10px;">
                    <div style="font-size: 10px; color: var(--text-secondary);">ORB Low (15m)</div>
                    <div class="price-val" id="orbLowVal" style="font-size: 16px; color: white;">₹0.00</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 10px;">
                    <div style="font-size: 10px; color: var(--text-secondary);">Trailing SL (VWAP)</div>
                    <div class="price-val" id="orbTrailVwapVal" style="font-size: 16px; color: white;">₹0.00</div>
                </div>
            </div>

            <!-- Trade Parameters -->
            <div id="orbTradeParameters" style="border-top: 1px solid rgba(255,255,255,0.05); padding-top: 16px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Breakout Entry Trigger</div>
                    <div class="price-val" id="orbEntryVal" style="color: white; font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Initial Stop-Loss</div>
                    <div class="price-val" id="orbSlVal" style="color: var(--accent-red); font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Target (1:2 R:R)</div>
                    <div class="price-val" id="orbTgtVal" style="color: var(--accent-green); font-size: 18px;">₹0.00</div>
                </div>
            </div>
        </div>

        <!-- Live Intraday VWAP & 20 EMA Setup -->
        <div class="intraday-setup-section" id="intradaySetupContainer" style="display: none; margin-bottom: 30px; border-radius: 16px; border: 1px dashed rgba(59, 130, 246, 0.4); padding: 24px; background: rgba(59, 130, 246, 0.02);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <h3 style="font-size: 16px; font-weight: 800; color: #3b82f6; display: flex; align-items: center; gap: 8px;">
                    ⚡ Live 5m Intraday Setup (VWAP + 20 EMA + Volume)
                </h3>
                <span class="decision-badge" id="intraDecisionBadge" style="font-size: 11px; font-weight: 800; padding: 4px 12px; border-radius: 20px; text-transform: uppercase;">BUY</span>
            </div>
            
            <p id="intraReason" style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.6;">Reasoning...</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 16px; margin-bottom: 20px;">
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 10px;">
                    <div style="font-size: 10px; color: var(--text-secondary);">VWAP Support</div>
                    <div class="price-val" id="intraVwapVal" style="font-size: 16px; color: white;">₹0.00</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 10px;">
                    <div style="font-size: 10px; color: var(--text-secondary);">20 EMA Support</div>
                    <div class="price-val" id="intraEmaVal" style="font-size: 16px; color: white;">₹0.00</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 12px; border-radius: 10px;">
                    <div style="font-size: 10px; color: var(--text-secondary);">5m Volume</div>
                    <div class="price-val" id="intraVolVal" style="font-size: 16px; color: white;">0</div>
                </div>
            </div>

            <!-- Trade Parameters -->
            <div id="intraTradeParameters" style="border-top: 1px solid rgba(255,255,255,0.05); padding-top: 16px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Suggested Buy Entry</div>
                    <div class="price-val" id="intraEntryVal" style="color: white; font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Intraday Stop-Loss</div>
                    <div class="price-val" id="intraSlVal" style="color: var(--accent-red); font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Intraday Target (1:2)</div>
                    <div class="price-val" id="intraTgtVal" style="color: var(--accent-green); font-size: 18px;">₹0.00</div>
                </div>
            </div>
        </div>

        <!-- Strategy Setups -->
        <div class="analysis-setups-section">
            <h3>Active Trading Setups Triggered</h3>
            <div class="setup-list" id="analysisSetupList">
                <div style="color: var(--text-secondary); font-size: 13px;">No direct strategies triggered for this stock today.</div>
            </div>
        </div>

        <!-- Indicators Breakdown -->
        <h3 class="indicators-grid-title">Technical Health Breakdown</h3>
        <div class="analysis-indicators-grid" id="analysisIndicatorsGrid">
            <!-- MA -->
            <div class="indicator-card">
                <h4>MA (9 vs 21)</h4>
                <div class="value" id="maVal">--</div>
                <div class="status" id="maStatus">Neutral</div>
                <p class="detail" id="maDetail">Loading details...</p>
            </div>
            <!-- RSI -->
            <div class="indicator-card">
                <h4>RSI (14)</h4>
                <div class="value" id="rsiVal">--</div>
                <div class="status" id="rsiStatus">Neutral</div>
                <p class="detail" id="rsiDetail">Loading details...</p>
            </div>
            <!-- Bollinger Bands -->
            <div class="indicator-card">
                <h4>Bollinger Bands</h4>
                <div class="value" id="bbVal">--</div>
                <div class="status" id="bbStatus">Neutral</div>
                <p class="detail" id="bbDetail">Loading details...</p>
            </div>
            <!-- MACD -->
            <div class="indicator-card">
                <h4>MACD</h4>
                <div class="value" id="macdVal">--</div>
                <div class="status" id="macdStatus">Neutral</div>
                <p class="detail" id="macdDetail">Loading details...</p>
            </div>
            <!-- Volume -->
            <div class="indicator-card">
                <h4>Volume Surge</h4>
                <div class="value" id="volVal">--</div>
                <div class="status" id="volStatus">Normal</div>
                <p class="detail" id="volDetail">Loading details...</p>
            </div>
        </div>

        <!-- News & Quarter Results -->
        <div class="analysis-news-section">
            <div class="news-header">
                <h3>Latest News & Quarterly Results</h3>
                <div id="resultsAlertBadge"></div>
            </div>
            <div class="news-list" id="analysisNewsList">
                <div style="color: var(--text-secondary); font-size: 13px;">No recent news articles found for this ticker.</div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card total">
            <span class="stat-label">Total Setups Found</span>
            <span class="stat-value">{{ $results->count() }}</span>
        </div>
        <div class="stat-card buys">
            <span class="stat-label">Buy Signals</span>
            <span class="stat-value" style="color: var(--accent-green);">{{ $results->where('signal', 'BUY')->count() }}</span>
        </div>
        <div class="stat-card sells">
            <span class="stat-label">Sell Signals (Short)</span>
            <span class="stat-value" style="color: var(--accent-red);">{{ $results->where('signal', 'SELL (short)')->count() }}</span>
        </div>
    </div>

    <!-- Layout Wrapper -->
    <div class="main-layout">
        <!-- Left Side: Table & Filters -->
        <div class="left-col">
            <!-- Filters Section -->
            <div class="filter-container">
                <button class="filter-btn active" onclick="filterStrategy('All', event)">All Strategies</button>
                <button class="filter-btn" onclick="filterStrategy('MA Crossover', event)">MA Crossover</button>
                <button class="filter-btn" onclick="filterStrategy('RSI Reversal', event)">RSI Reversal</button>
                <button class="filter-btn" onclick="filterStrategy('Bollinger Bands Breakout', event)">Bollinger Bands</button>
                <button class="filter-btn" onclick="filterStrategy('MACD Crossover', event)">MACD</button>
                <button class="filter-btn" onclick="filterStrategy('Volume Breakout', event)">Volume Breakout</button>
                <button class="filter-btn" onclick="filterStrategy('Positive Earnings', event)">Positive Earnings</button>
                <button class="filter-btn" onclick="filterStrategy('ORB + VWAP Breakout', event)">ORB + VWAP Breakout</button>
                <button class="filter-btn" onclick="filterStrategy('High Momentum', event)">High Momentum</button>
            </div>

            <!-- Table of Setups -->
            <div class="table-card">
                @if($results->isEmpty())
                    <div class="empty-state">
                        <h3>No setups found today</h3>
                        <p>Ensure the screener command (`php artisan screener:run`) has run, or customize criteria to find more setups.</p>
                    </div>
                @else
                    <table id="setupsTable">
                        <thead>
                        <tr>
                            <th>Symbol</th>
                            <th>Strategy</th>
                            <th>Signal</th>
                            <th>Entry Price</th>
                            <th>Stop-Loss</th>
                            <th>Target (1:2 R:R)</th>
                            <th>Live Price</th>
                            <th>Trade Status</th>
                            <th>Reason / Detail</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($results as $r)
                            <tr class="setup-row" data-strategy="{{ $r->strategy }}">
                                <td>
                                    <div class="symbol-badge">{{ $r->symbol }}</div>
                                    <div class="symbol-ns">NSE India</div>
                                </td>
                                <td>
                                    @php
                                        $badgeClass = 'strat-ma';
                                        if($r->strategy === 'RSI Reversal') $badgeClass = 'strat-rsi';
                                        if($r->strategy === 'Bollinger Bands Breakout') $badgeClass = 'strat-bb';
                                        if($r->strategy === 'MACD Crossover') $badgeClass = 'strat-macd';
                                        if($r->strategy === 'Volume Breakout') $badgeClass = 'strat-vol';
                                        if($r->strategy === 'Positive Earnings') $badgeClass = 'strat-earnings';
                                        if($r->strategy === 'ORB + VWAP Breakout') $badgeClass = 'strat-orb';
                                        if($r->strategy === 'High Momentum') $badgeClass = 'strat-high-momentum';
                                    @endphp
                                    <span class="strategy-badge {{ $badgeClass }}">{{ $r->strategy }}</span>
                                </td>
                                <td>
                                    <span class="signal-pill {{ str_contains($r->signal, 'BUY') ? 'buy' : 'sell' }}">
                                        {{ str_contains($r->signal, 'BUY') ? '▲ BUY' : '▼ SELL' }}
                                    </span>
                                    @if($r->volume_surge)
                                        <span class="volume-badge">Vol Surge</span>
                                    @endif
                                </td>
                                <td class="price-val">₹{{ number_format($r->entry, 2) }}</td>
                                <td class="price-val" style="color: var(--accent-red);">₹{{ number_format($r->stop_loss, 2) }}</td>
                                <td class="price-val" style="color: var(--accent-green);">₹{{ number_format($r->target, 2) }}</td>
                                <td class="price-val" id="live-price-{{ $r->id }}">
                                    <div class="live-price-container">
                                        <span>Loading...</span>
                                        <span class="live-percent">--</span>
                                    </div>
                                </td>
                                <td id="live-status-{{ $r->id }}">
                                    <span class="status-pill active">Connecting...</span>
                                </td>
                                <td>
                                    <div class="reason-text">{{ $r->reason }}</div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <!-- Right Side: Earnings Calendar Widget -->
        <div class="right-col">
            <div class="calendar-card">
                <h3>📅 Earnings Event Board</h3>
                <p style="font-size:12px; color:var(--text-secondary); margin-bottom:15px; line-height:1.4;">Quarterly results releases that trigger massive volatility. Click Analyze to run deep technical analysis on any stock.</p>
                
                <div class="calendar-section-title">Tomorrow's Results (Aug 6)</div>
                <div class="calendar-list">
                    @foreach($earningsTomorrow as $earnings)
                        <div class="calendar-item">
                            <div class="calendar-item-info">
                                <span class="calendar-symbol">{{ $earnings['symbol'] }}</span>
                                <span class="calendar-name" title="{{ $earnings['name'] }}">{{ $earnings['name'] }}</span>
                            </div>
                            <button class="calendar-action-btn" onclick="triggerCalendarSearch('{{ $earnings['symbol'] }}')">Analyze</button>
                        </div>
                    @endforeach
                </div>

                <div class="calendar-section-title">Today's Results (Aug 5)</div>
                <div class="calendar-list">
                    @foreach($earningsToday as $earnings)
                        <div class="calendar-item">
                            <div class="calendar-item-info">
                                <span class="calendar-symbol">{{ $earnings['symbol'] }}</span>
                                <span class="calendar-name" title="{{ $earnings['name'] }}">{{ $earnings['name'] }}</span>
                            </div>
                            <button class="calendar-action-btn" onclick="triggerCalendarSearch('{{ $earnings['symbol'] }}')">Analyze</button>
                        </div>
                    @endforeach
                </div>

                <div class="calendar-section-title">Yesterday's Results (Aug 4)</div>
                <div class="calendar-list">
                    @foreach($earningsYesterday as $earnings)
                        <div class="calendar-item">
                            <div class="calendar-item-info">
                                <span class="calendar-symbol">{{ $earnings['symbol'] }}</span>
                                <span class="calendar-name" title="{{ $earnings['name'] }}">{{ $earnings['name'] }}</span>
                            </div>
                            <button class="calendar-action-btn" onclick="triggerCalendarSearch('{{ $earnings['symbol'] }}')">Analyze</button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- Strategy Explanations (Helpful Guide) -->
    <section class="guide-section">
        <h2>Learn & Understand Intraday Strategies</h2>
        <div class="guide-grid">
            <div class="guide-card">
                <h3><span class="badge strat-ma">MA</span> MA Crossover Strategy</h3>
                <p><strong>How it works:</strong> Monitors the fast 9-day SMA and slow 21-day SMA. When the 9 SMA crosses above the 21 SMA, it flags a bullish entry. When it crosses below, it signals a short entry. Best used in trending markets.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-rsi">RSI</span> RSI Reversal Strategy</h3>
                <p><strong>How it works:</strong> Uses the Relative Strength Index (14) to flag oversold (&lt; 30) or overbought (&gt; 70) conditions. A buy setup triggers when RSI rises back above 30, and a sell setup triggers when RSI falls back below 70.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-bb">BB</span> Bollinger Bands Breakout</h3>
                <p><strong>How it works:</strong> Consists of a simple moving average and standard deviations. When the closing price shoots above the upper band, it signals a strong bullish breakout. Below the lower band signals a bearish breakdown.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-macd">MC</span> MACD Crossover Strategy</h3>
                <p><strong>How it works:</strong> Calculates the difference between the 12 EMA and 26 EMA (MACD line). A signal line is a 9 EMA of the MACD line. Crossover above the signal line indicates upward momentum; below indicates downward momentum.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-vol">VO</span> Volume Breakout Strategy</h3>
                <p><strong>How it works:</strong> Looks for candles with extreme volume (&gt; 2.5x the 20-day average volume). Large volumes indicate institutional interest or severe breakouts, giving traders high confidence to follow the direction.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-earnings">PE</span> Positive Earnings Strategy</h3>
                <p><strong>How it works:</strong> Scans corporate headlines published in the last 48 hours for quarterly results. If a company reports a profit increase or positive earnings beat, it triggers a BUY setup on the premise of strong news momentum.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-orb">OR</span> ORB + VWAP Strategy</h3>
                <p><strong>How it works:</strong> Tracks the first 15 minutes of trading (9:15-9:30 AM). Triggers a trade when price breaks the range high (long) or low (short), confirmed by volume and VWAP. Exit targets are 1:2 R:R or trailing VWAP.</p>
            </div>
            <div class="guide-card">
                <h3><span class="badge strat-high-momentum">HM</span> High Momentum</h3>
                <p><strong>How it works:</strong> Flags stocks experiencing extreme daily movement, rising by 3% or more (bullish momentum) or falling by 3% or more (bearish selling pressure) from their previous close.</p>
            </div>
        </div>
    </section>

    <footer class="disclaimer">
        These technical indicators and strategies are rule-based systems to aid screening, not direct financial advice or predictive guarantees. Stock values are based on historical daily Yahoo Finance feeds. Always verify live market metrics and price actions inside your broker's application prior to entry.
    </footer>
</div>

<script>
    function filterStrategy(strategy, event) {
        // Toggle active button
        const buttons = document.querySelectorAll('.filter-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        event.target.classList.add('active');

        // Filter rows
        const rows = document.querySelectorAll('.setup-row');
        rows.forEach(row => {
            const rowStrat = row.getAttribute('data-strategy');
            if (strategy === 'All' || rowStrat === strategy) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Fetch live prices and update elements
    function fetchLivePrices() {
        fetch('{{ route('screener.live') }}')
            .then(res => res.json())
            .then(data => {
                Object.keys(data).forEach(id => {
                    const priceData = data[id];
                    
                    // Update price & percentage
                    const priceContainer = document.getElementById(`live-price-${id}`);
                    if (priceContainer) {
                        const isProfit = priceData.change_percent >= 0;
                        const sign = isProfit ? '+' : '';
                        
                        priceContainer.innerHTML = `
                            <div class="live-price-container">
                                <span>₹${priceData.current.toFixed(2)}</span>
                                <span class="live-percent ${isProfit ? 'profit' : 'loss'}">
                                    ${sign}${priceData.change_percent.toFixed(2)}%
                                </span>
                            </div>
                        `;
                    }

                    // Update trade status pill
                    const statusTd = document.getElementById(`live-status-${id}`);
                    if (statusTd) {
                        let pillClass = 'active';
                        if (priceData.status.includes('SL Hit')) pillClass = 'sl';
                        if (priceData.status.includes('Target Hit')) pillClass = 'target';
                        
                        statusTd.innerHTML = `<span class="status-pill ${pillClass}">${priceData.status}</span>`;
                    }
                });
            })
            .catch(err => console.error("Error fetching live quotes:", err));
    }

    // Trigger search from the earnings widget
    function triggerCalendarSearch(symbol) {
        document.getElementById('searchInput').value = symbol;
        analyzeStock();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Search and analyze stock
    function analyzeStock() {
        const symbol = document.getElementById('searchInput').value.trim();
        if (!symbol) {
            alert('Please enter a stock symbol.');
            return;
        }

        const reportCard = document.getElementById('analysisReportCard');
        reportCard.style.display = 'block';
        reportCard.style.borderColor = '#27272a';
        
        // Put loading indicators
        document.getElementById('analysisSymbol').innerText = symbol.toUpperCase();
        document.getElementById('analysisPrice').innerText = 'Loading...';
        document.getElementById('analysisChange').innerText = '';
        document.getElementById('analysisBiasBadge').innerHTML = '';
        document.getElementById('analysisSetupList').innerHTML = '<div style="color: var(--text-secondary);">Analyzing charts...</div>';
        document.getElementById('resultsAlertBadge').innerHTML = '';
        document.getElementById('analysisNewsList').innerHTML = '<div style="color: var(--text-secondary);">Searching headlines...</div>';
        document.getElementById('intradaySetupContainer').style.display = 'none';
        document.getElementById('orbSetupContainer').style.display = 'none';

        fetch(`/screener/analyze?symbol=${symbol}`)
            .then(res => {
                if (!res.ok) {
                    return res.json().then(err => { throw new Error(err.error || 'Fetch failed') });
                }
                return res.json();
            })
            .then(data => {
                reportCard.style.borderColor = '#3b82f6';
                document.getElementById('analysisPrice').innerText = `₹${data.current_price.toFixed(2)}`;
                
                const isPositive = data.change_percent >= 0;
                document.getElementById('analysisChange').innerText = `${isPositive ? '+' : ''}${data.change_percent.toFixed(2)}%`;
                document.getElementById('analysisChange').className = `analysis-change ${isPositive ? 'profit' : 'loss'}`;

                // Bias Badge
                let biasClass = 'bias-neutral';
                if (data.bias === 'BULLISH') biasClass = 'bias-bullish';
                if (data.bias === 'BEARISH') biasClass = 'bias-bearish';
                document.getElementById('analysisBiasBadge').innerHTML = `<span class="bias-badge ${biasClass}">${data.bias} (${data.bullish_indicators} of 5 Bullish)</span>`;

                // Setups Triggered
                const setupList = document.getElementById('analysisSetupList');
                if (data.setups.length === 0) {
                    setupList.innerHTML = '<div style="color: var(--text-secondary); font-size: 13px;">No direct strategies triggered for this stock today.</div>';
                } else {
                    setupList.innerHTML = data.setups.map(s => {
                        const isBuy = s.signal.includes('BUY');
                        return `
                            <div class="analysis-setup-card ${isBuy ? 'buy' : 'sell'}">
                                <div style="font-weight:800; font-size:12px; text-transform:uppercase; color:${isBuy ? 'var(--accent-green)' : 'var(--accent-red)'}">
                                    ${isBuy ? '▲ BUY TRIGGER' : '▼ SELL (SHORT) TRIGGER'}
                                </div>
                                <div style="font-size:11px; font-weight:700; color:var(--text-secondary); margin-top:2px;">Strategy: ${s.strategy}</div>
                                <div style="margin-top:12px; display:grid; grid-template-columns: repeat(3, 1fr); gap:8px;">
                                    <div>
                                        <div style="font-size:10px; color:var(--text-secondary)">Entry</div>
                                        <div class="price-val">₹${s.entry.toFixed(2)}</div>
                                    </div>
                                    <div style="color: var(--accent-red)">
                                        <div style="font-size:10px; color:var(--text-secondary)">Stop-Loss</div>
                                        <div class="price-val">₹${s.stop_loss.toFixed(2)}</div>
                                    </div>
                                    <div style="color: var(--accent-green)">
                                        <div style="font-size:10px; color:var(--text-secondary)">Target</div>
                                        <div class="price-val">₹${s.target.toFixed(2)}</div>
                                    </div>
                                </div>
                                <div style="font-size:12px; color:var(--text-secondary); margin-top:12px; border-top:1px solid #222; padding-top:8px;">
                                    Reason: ${s.reason}
                                </div>
                            </div>
                        `;
                    }).join('');
                }

                // Indicators grid
                document.getElementById('maVal').innerText = `₹${data.indicators.ma.short_val.toFixed(1)} / ₹${data.indicators.ma.long_val.toFixed(1)}`;
                document.getElementById('maStatus').innerText = data.indicators.ma.status;
                document.getElementById('maStatus').className = `status ${data.indicators.ma.status.toLowerCase()}`;
                document.getElementById('maDetail').innerText = data.indicators.ma.detail;

                document.getElementById('rsiVal').innerText = data.indicators.rsi.value.toFixed(1);
                let rsiStat = 'neutral';
                if (data.indicators.rsi.status.includes('Oversold')) rsiStat = 'buy';
                if (data.indicators.rsi.status.includes('Overbought')) rsiStat = 'sell';
                document.getElementById('rsiStatus').innerText = data.indicators.rsi.status;
                document.getElementById('rsiStatus').className = `status ${rsiStat}`;
                document.getElementById('rsiDetail').innerText = data.indicators.rsi.detail;

                document.getElementById('bbVal').innerText = `₹${data.indicators.bb.upper.toFixed(1)} / ₹${data.indicators.bb.lower.toFixed(1)}`;
                let bbStat = 'neutral';
                if (data.indicators.bb.status.includes('Bullish')) bbStat = 'buy';
                if (data.indicators.bb.status.includes('Bearish')) bbStat = 'sell';
                document.getElementById('bbStatus').innerText = data.indicators.bb.status;
                document.getElementById('bbStatus').className = `status ${bbStat}`;
                document.getElementById('bbDetail').innerText = data.indicators.bb.detail;

                document.getElementById('macdVal').innerText = `${data.indicators.macd.macd.toFixed(2)} / ${data.indicators.macd.signal.toFixed(2)}`;
                document.getElementById('macdStatus').innerText = data.indicators.macd.status;
                document.getElementById('macdStatus').className = `status ${data.indicators.macd.status.toLowerCase()}`;
                document.getElementById('macdDetail').innerText = data.indicators.macd.detail;

                document.getElementById('volVal').innerText = `${(data.indicators.volume.current / 100000).toFixed(1)}L`;
                let volStat = 'neutral';
                if (data.indicators.volume.status.includes('Surge')) volStat = 'buy';
                document.getElementById('volStatus').innerText = data.indicators.volume.status;
                document.getElementById('volStatus').className = `status ${volStat}`;
                document.getElementById('volDetail').innerText = data.indicators.volume.detail;

                // Live Intraday VWAP & 20 EMA Section
                const intraContainer = document.getElementById('intradaySetupContainer');
                if (data.intraday_setup) {
                    intraContainer.style.display = 'block';
                    document.getElementById('intraReason').innerText = data.intraday_setup.reason;
                    document.getElementById('intraVwapVal').innerText = `₹${data.intraday_setup.vwap.toFixed(2)}`;
                    document.getElementById('intraEmaVal').innerText = `₹${data.intraday_setup.ema20.toFixed(2)}`;
                    document.getElementById('intraVolVal').innerText = `${(data.intraday_setup.volume / 1000).toFixed(1)}K / Avg: ${(data.intraday_setup.avg_volume / 1000).toFixed(1)}K`;

                    const decisionBadge = document.getElementById('intraDecisionBadge');
                    decisionBadge.innerText = data.intraday_setup.decision;
                    
                    const isBuy = data.intraday_setup.action === 'BUY';
                    if (isBuy) {
                        decisionBadge.style.background = 'var(--glow-green)';
                        decisionBadge.style.color = 'var(--accent-green)';
                        decisionBadge.style.border = '1px solid rgba(16, 185, 129, 0.3)';
                        
                        document.getElementById('intraTradeParameters').style.display = 'grid';
                        document.getElementById('intraEntryVal').innerText = `₹${data.intraday_setup.price.toFixed(2)}`;
                        document.getElementById('intraSlVal').innerText = `₹${data.intraday_setup.stop_loss.toFixed(2)}`;
                        document.getElementById('intraTgtVal').innerText = `₹${data.intraday_setup.target.toFixed(2)}`;
                    } else {
                        decisionBadge.style.background = 'rgba(255,255,255,0.05)';
                        decisionBadge.style.color = 'var(--text-secondary)';
                        decisionBadge.style.border = '1px solid var(--border-color)';
                        document.getElementById('intraTradeParameters').style.display = 'none';
                    }
                } else {
                    intraContainer.style.display = 'none';
                }

                // Live ORB + VWAP Section
                const orbContainer = document.getElementById('orbSetupContainer');
                if (data.orb_setup) {
                    orbContainer.style.display = 'block';
                    document.getElementById('orbReason').innerText = data.orb_setup.reason;
                    document.getElementById('orbHighVal').innerText = `₹${data.orb_setup.orb_high.toFixed(2)}`;
                    document.getElementById('orbLowVal').innerText = `₹${data.orb_setup.orb_low.toFixed(2)}`;
                    document.getElementById('orbTrailVwapVal').innerText = `₹${data.orb_setup.trail_sl.toFixed(2)}`;

                    const orbBadge = document.getElementById('orbDecisionBadge');
                    orbBadge.innerText = data.orb_setup.status;
                    
                    const isTriggered = data.orb_setup.status === 'Active';
                    if (isTriggered) {
                        const isBuy = data.orb_setup.type.includes('BUY');
                        orbBadge.style.background = isBuy ? 'var(--glow-green)' : 'var(--glow-red)';
                        orbBadge.style.color = isBuy ? 'var(--accent-green)' : 'var(--accent-red)';
                        orbBadge.style.border = isBuy ? '1px solid rgba(16, 185, 129, 0.3)' : '1px solid rgba(239, 68, 68, 0.3)';
                    } else {
                        orbBadge.style.background = 'rgba(255,255,255,0.05)';
                        orbBadge.style.color = 'var(--text-secondary)';
                        orbBadge.style.border = '1px solid var(--border-color)';
                    }

                    document.getElementById('orbEntryVal').innerText = `₹${data.orb_setup.entry.toFixed(2)} (${data.orb_setup.type})`;
                    document.getElementById('orbSlVal').innerText = `₹${data.orb_setup.stop_loss.toFixed(2)}`;
                    document.getElementById('orbTgtVal').innerText = `₹${data.orb_setup.target.toFixed(2)}`;
                } else {
                    orbContainer.style.display = 'none';
                }

                // News & Results Alert
                if (data.has_quarterly_result) {
                    const isPositive = data.result_sentiment.includes('Positive');
                    document.getElementById('resultsAlertBadge').innerHTML = `
                        <span class="results-badge" style="background:${isPositive ? 'var(--glow-green)' : 'var(--glow-red)'}; color:${isPositive ? 'var(--accent-green)' : 'var(--accent-red)'}">
                            Earnings Result: ${data.result_sentiment}
                        </span>
                    `;
                }

                // News headlines
                const newsList = document.getElementById('analysisNewsList');
                if (data.news.length === 0) {
                    newsList.innerHTML = '<div style="color: var(--text-secondary); font-size: 13px;">No recent news articles found for this ticker.</div>';
                } else {
                    newsList.innerHTML = data.news.map(n => `
                        <div class="news-item">
                            <div class="news-info">
                                <a href="${n.link}" target="_blank" class="news-title">${n.title}</a>
                                <div class="news-meta">${n.publisher} • ${n.time}</div>
                            </div>
                            ${n.is_quarter ? '<span class="quarter-tag">Results News</span>' : ''}
                        </div>
                    `).join('');
                }
            })
            .catch(err => {
                reportCard.style.borderColor = 'var(--accent-red)';
                document.getElementById('analysisSetupList').innerHTML = `<div style="color: var(--accent-red); font-size: 14px;">Error: ${err.message}</div>`;
            });
    }

    // Close analysis card
    function closeAnalysis() {
        document.getElementById('analysisReportCard').style.display = 'none';
    }

    // Fetch immediately on load and then every 30 seconds
    document.addEventListener("DOMContentLoaded", () => {
        fetchLivePrices();
        setInterval(fetchLivePrices, 30000);
    });
</script>
</body>
</html>
