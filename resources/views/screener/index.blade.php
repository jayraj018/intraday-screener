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
            min-width: min(250px, 100%);
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
            grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr));
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
            grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
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
            min-width: min(250px, 100%);
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
            grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
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
            /* auto, not hidden: still clips to the rounded corners, but wide tables scroll
               sideways on tablets instead of having their right-hand columns cut off */
            overflow-x: auto;
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

        /* Top Picks */
        .top-picks-card {
            border-color: rgba(245, 158, 11, 0.35);
            margin-bottom: 30px;
        }

        .top-picks-header {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: 10px;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .top-picks-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .top-picks-header span,
        .top-picks-empty {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .top-picks-empty {
            padding: 24px;
        }

        .top-picks-qualified {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 12px 24px;
            font-size: 12px;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        .agree-score {
            font-size: 20px;
            font-weight: 800;
            color: var(--accent-orange);
        }

        .opposing-note {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .strategy-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            max-width: 340px;
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
            grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr));
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
            .left-col {
                width: 100%;
            }
            .right-col {
                width: 100%;
                min-width: 0;
                position: static;
                /* Stacked, the setups table can run to hundreds of rows; keep the short
                   events board above it instead of at the very bottom of the page */
                order: -1;
            }
        }

        /* Phones */
        @media (max-width: 640px) {
            body {
                padding: 20px 12px;
            }

            header {
                margin-bottom: 20px;
                padding-bottom: 16px;
            }

            .logo-area h1 {
                font-size: 24px;
            }

            .logo-area p,
            .scan-time {
                font-size: 12px;
            }

            .search-section {
                padding: 14px;
                margin-bottom: 20px;
            }

            .search-btn {
                width: 100%;
            }

            .analysis-card {
                padding: 20px 16px;
                margin-bottom: 24px;
            }

            .close-analysis-btn {
                top: 12px;
                right: 12px;
            }

            .analysis-header {
                padding-right: 36px; /* keep the symbol clear of the close button */
            }

            .analysis-title-group h2 {
                font-size: 22px;
            }

            .analysis-price-group {
                text-align: left;
            }

            .analysis-price {
                font-size: 24px;
            }

            .analysis-setups-section,
            .intraday-setup-section {
                padding: 16px !important;
            }

            .news-header {
                flex-wrap: wrap;
                gap: 8px;
            }

            /* Three compact stats side by side rather than three full-width rows */
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                margin-bottom: 20px;
            }

            .stat-card {
                padding: 14px 12px;
            }

            .stat-label {
                font-size: 10px;
                letter-spacing: 0.5px;
            }

            .stat-value {
                font-size: 26px;
            }

            .filter-btn {
                padding: 8px 14px;
                font-size: 13px;
            }

            .top-picks-header,
            .top-picks-qualified {
                padding-left: 16px;
                padding-right: 16px;
            }

            .empty-state {
                padding: 48px 20px;
            }

            /* Tables become one card per row: label on the left, value on the right.
               Cells keep their ids, so the live price/status updates still find them. */
            .card-table thead {
                display: none;
            }

            .card-table,
            .card-table tbody,
            .card-table tr {
                display: block;
                width: 100%;
            }

            /* Grid so Entry / Stop-Loss / Target sit side by side; every other cell spans the row */
            .card-table tr {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                column-gap: 12px;
                padding: 14px 16px;
                border-bottom: 1px solid var(--border-color);
            }

            .card-table tr:last-child {
                border-bottom: none;
            }

            .card-table td {
                grid-column: 1 / -1;
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 8px;
                padding: 6px 0;
                border-bottom: none;
                text-align: right;
            }

            .card-table td::before {
                content: attr(data-label);
                flex-shrink: 0;
                /* price cells use a monospace font; labels shouldn't inherit it */
                font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                margin-right: auto; /* label left; however many value pieces follow stay grouped on the right */
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: var(--text-secondary);
                text-align: left;
            }

            .card-table tr:hover td {
                background: transparent;
            }

            .card-table td.cell-title {
                display: block;
                text-align: left;
                padding-bottom: 8px;
            }

            .card-table td.cell-title::before,
            .card-table td.cell-block::before {
                display: block;
                margin-bottom: 6px;
            }

            .card-table td.cell-title::before {
                content: none;
            }

            .card-table td.cell-block {
                display: block;
                text-align: left;
            }

            .card-table td.cell-price {
                grid-column: auto;
                display: block;
                text-align: left;
                padding-top: 10px;
                font-size: 14px;
            }

            .card-table td.cell-price::before {
                display: block;
                margin-right: 0;
                margin-bottom: 2px;
            }

            .card-table .strategy-list {
                max-width: none;
                justify-content: flex-end;
            }

            .card-table .live-price-container {
                align-items: flex-end;
            }

            .reason-text {
                max-width: none;
            }

            .calendar-card {
                padding: 18px 16px;
            }

            .calendar-name {
                max-width: 45vw;
            }

            .guide-section {
                margin-top: 40px;
            }

            .guide-section h2 {
                font-size: 18px;
            }

            .guide-card {
                padding: 18px;
            }

            .disclaimer {
                margin-top: 40px;
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
                    <div style="font-size: 10px; color: var(--text-secondary);" id="orbEntryLabel">Entry</div>
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
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);" id="orbOutcomeLabel">Result</div>
                    <div class="price-val" id="orbOutcomeVal" style="color: white; font-size: 18px;">—</div>
                </div>
            </div>

            <!-- Breakout confirmed on the newest candle: the entry price hasn't printed yet -->
            <div id="orbPendingNote" style="display: none; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 16px; font-size: 13px; color: var(--text-secondary);">
                Breakout confirmed on the latest 5-minute candle. The entry is the <strong>open of the next candle</strong>, which hasn't printed yet — there is no fill price to show.
            </div>

            <p style="margin-top: 16px; font-size: 11px; color: var(--text-secondary); line-height: 1.6;">
                This setup is a record of what the rules did earlier in the session, not an order to place now. The entry shown is the fill the breakout would have received at the time — the current price is at the top of this panel.
            </p>
        </div>

        <!-- Can I trade this now? The only panel whose entry price is still available -->
        <div class="intraday-setup-section" id="tradeNowContainer" style="display: none; margin-bottom: 30px; border-radius: 16px; border: 1px solid rgba(59, 130, 246, 0.4); padding: 24px; background: rgba(59, 130, 246, 0.03);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <h3 style="font-size: 16px; font-weight: 800; color: #3b82f6; display: flex; align-items: center; gap: 8px;">
                    ⚡ Can I trade this now?
                </h3>
                <span class="decision-badge" id="tradeNowBadge" style="font-size: 13px; font-weight: 800; padding: 6px 16px; border-radius: 20px; text-transform: uppercase;">WAIT</span>
            </div>

            <p id="tradeNowHeadline" style="font-size: 14px; color: white; margin-bottom: 16px; line-height: 1.6;"></p>

            <!-- Every rule, and whether it passes right now -->
            <div id="tradeNowChecks" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; margin-bottom: 20px;"></div>

            <!-- Shown only when the answer is BUY or SELL -->
            <div id="tradeNowLevels" style="display: none; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 16px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px;">
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Entry (price right now)</div>
                    <div class="price-val" id="tradeNowEntry" style="color: white; font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Stop-Loss</div>
                    <div class="price-val" id="tradeNowStop" style="color: var(--accent-red); font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Target</div>
                    <div class="price-val" id="tradeNowTarget" style="color: var(--accent-green); font-size: 18px;">₹0.00</div>
                </div>
                <div>
                    <div style="font-size: 10px; color: var(--text-secondary);">Square off by</div>
                    <div class="price-val" id="tradeNowSquareOff" style="color: white; font-size: 18px;">15:15</div>
                </div>
            </div>

            <!-- Shown only when the answer is WAIT -->
            <div id="tradeNowWatch" style="display: none; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 16px;">
                <div style="font-size: 10px; color: var(--text-secondary); margin-bottom: 6px;">What to watch for</div>
                <div id="tradeNowWatchText" style="font-size: 14px; color: white; line-height: 1.6;"></div>
            </div>

            <p style="margin-top: 16px; font-size: 11px; color: var(--text-secondary); line-height: 1.6;">
                These levels are measured against the latest candle, so the entry is a price still available — unlike the setups above, which record what the rules did earlier. This reports whether the conditions are met; it cannot tell you whether the trade will work out.
            </p>
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

    @php
        $strategyBadgeClasses = [
            'MA Crossover' => 'strat-ma',
            'RSI Reversal' => 'strat-rsi',
            'Bollinger Bands Breakout' => 'strat-bb',
            'MACD Crossover' => 'strat-macd',
            'Volume Breakout' => 'strat-vol',
            'Positive Earnings' => 'strat-earnings',
            'ORB + VWAP Breakout' => 'strat-orb',
            'High Momentum' => 'strat-high-momentum',
        ];
    @endphp

    <!-- Top Picks: stocks where several strategies agree on the same direction -->
    <div class="table-card top-picks-card">
        <div class="top-picks-header">
            <h3>⭐ Top Picks</h3>
            <span>Stocks where {{ $minAgree }} or more strategies give the same signal today — counting only strategies that made money after costs in the last backtest</span>
        </div>
        @if($qualifiedStrategies->isNotEmpty())
            <div class="top-picks-qualified">
                Counted:
                @foreach($qualifiedStrategies as $stat)
                    <span class="strategy-badge {{ $strategyBadgeClasses[$stat->strategy] ?? 'strat-ma' }}">{{ $stat->strategy }} · {{ round($stat->win_rate) }}%{{ $stat->expectancy_r === null ? '' : ' · ' . number_format($stat->expectancy_r, 2) . 'R' }}</span>
                @endforeach
            </div>
        @endif
        @if($strategyStats->isEmpty())
            <div class="top-picks-empty">Nothing has been measured yet. Run <code>php artisan screener:backtest</code> first.</div>
        @elseif($qualifiedStrategies->isEmpty())
            {{-- A strategy can clear the win-rate bar and still be rejected here for losing
                 more on its losers than it makes on its winners, so say which gate it failed. --}}
            <div class="top-picks-empty">
                No strategy qualified in the last backtest, so there are no Top Picks.
                A strategy needs at least {{ $minWinRate }}% wins, {{ config('screener.min_backtest_trades') }} trades, and an expectancy above {{ number_format($minExpectancy, 2) }}R after costs.
                @php($profitable = $strategyStats->filter(fn ($s) => $s->expectancy_r !== null && $s->expectancy_r >= $minExpectancy))
                @if($strategyStats->contains(fn ($s) => $s->expectancy_r !== null) && $profitable->isEmpty())
                    <br><strong>Every strategy lost money after costs in that run</strong> — the numbers are in the backtest output, and this panel stays empty until that changes.
                @endif
            </div>
        @elseif($topPicks->isEmpty())
            <div class="top-picks-empty">No stock has {{ $minAgree }} or more of these strategies agreeing today.</div>
        @else
            <div style="overflow-x: auto;">
                <table class="card-table">
                    <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Signal</th>
                        <th>Agree</th>
                        <th>Strategies</th>
                        <th>Entry Price</th>
                        <th>Stop-Loss</th>
                        <th>Target (1:2 R:R)</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($topPicks as $pick)
                        <tr>
                            <td class="cell-title">
                                <div class="symbol-badge">{{ $pick->symbol }}</div>
                                <div class="symbol-ns">NSE India</div>
                            </td>
                            <td data-label="Signal">
                                <span class="signal-pill {{ $pick->direction === 'BUY' ? 'buy' : 'sell' }}">
                                    {{ $pick->direction === 'BUY' ? '▲ BUY' : '▼ SELL' }}
                                </span>
                                @if($pick->volume_surge)
                                    <span class="volume-badge">Vol Surge</span>
                                @endif
                            </td>
                            <td data-label="Agree">
                                <span class="agree-score">{{ $pick->score }}</span>
                                @if($pick->opposing)
                                    <div class="opposing-note">⚠ {{ $pick->opposing }} opposite</div>
                                @endif
                            </td>
                            <td class="cell-block" data-label="Strategies">
                                <div class="strategy-list">
                                    @foreach($pick->strategies as $strategy)
                                        <span class="strategy-badge {{ $strategyBadgeClasses[$strategy] ?? 'strat-ma' }}">{{ $strategy }} · {{ round($strategyStats[$strategy]->win_rate) }}%</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="price-val cell-price" data-label="Entry">₹{{ number_format($pick->entry, 2) }}</td>
                            <td class="price-val cell-price" data-label="Stop-Loss" style="color: var(--accent-red);">₹{{ number_format($pick->stop_loss, 2) }}</td>
                            <td class="price-val cell-price" data-label="Target" style="color: var(--accent-green);">₹{{ number_format($pick->target, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- What the backtest actually measured. Win rate alone can't separate a strategy with
         an edge from one without: at a 1:2 payoff, 34% wins is already break-even. --}}
    @if($strategyStats->isNotEmpty())
        <div class="top-picks-card" style="margin-bottom: 30px;">
            <div class="top-picks-header" style="flex-wrap: wrap; gap: 8px;">
                <span class="top-picks-title">📊 Backtest Results</span>
                <span>
                    @if($backtestRun)
                        {{ $backtestRun->range }} of history · {{ number_format($backtestRun->symbols) }} stocks ·
                        entry at {{ str_replace('_', ' ', $backtestRun->settings['execution']['entry'] ?? 'unknown') }} ·
                        run #{{ $backtestRun->id }}{{ $backtestRun->finished_at ? ', ' . $backtestRun->finished_at->timezone('Asia/Kolkata')->format('d M Y H:i') : '' }}
                    @else
                        from an earlier run, before individual trades were recorded
                    @endif
                </span>
            </div>

            <div style="overflow-x: auto;">
                <table class="card-table">
                    <thead>
                    <tr>
                        <th>Strategy</th>
                        <th>Trades</th>
                        <th>Win rate</th>
                        <th>Avg win</th>
                        <th>Avg loss</th>
                        <th>Profit factor</th>
                        <th>Expectancy</th>
                        <th>Max drawdown</th>
                        <th>How trades ended</th>
                        <th>Counts in Top Picks</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($strategyStats->sortByDesc('expectancy_r') as $stat)
                        <tr>
                            <td class="cell-block" data-label="Strategy">
                                <span class="strategy-badge {{ $strategyBadgeClasses[$stat->strategy] ?? 'strat-ma' }}">{{ $stat->strategy }}</span>
                            </td>
                            <td data-label="Trades">{{ number_format($stat->trades) }}</td>
                            <td data-label="Win rate">{{ number_format($stat->win_rate, 1) }}%</td>
                            <td class="price-val" data-label="Avg win">{{ $stat->avg_win_r === null ? '—' : number_format($stat->avg_win_r, 2) . 'R' }}</td>
                            <td class="price-val" data-label="Avg loss">{{ $stat->avg_loss_r === null ? '—' : number_format($stat->avg_loss_r, 2) . 'R' }}</td>
                            {{-- Below 1.0 means the losers took more than the winners made --}}
                            <td class="price-val" data-label="Profit factor" style="color: {{ $stat->profit_factor === null ? 'inherit' : ($stat->profit_factor >= 1 ? 'var(--accent-green)' : 'var(--accent-red)') }};">
                                {{ $stat->profit_factor === null ? '—' : number_format($stat->profit_factor, 2) }}
                            </td>
                            <td class="price-val" data-label="Expectancy" style="color: {{ $stat->expectancy_r === null ? 'inherit' : ($stat->expectancy_r >= 0 ? 'var(--accent-green)' : 'var(--accent-red)') }};">
                                {{ $stat->expectancy_r === null ? '—' : number_format($stat->expectancy_r, 3) . 'R' }}
                            </td>
                            <td class="price-val" data-label="Max drawdown">{{ $stat->max_drawdown_r === null ? '—' : number_format($stat->max_drawdown_r, 1) . 'R' }}</td>
                            <td class="cell-block" data-label="How trades ended">
                                @if($stat->exit_breakdown)
                                    @php($total = max(1, array_sum($stat->exit_breakdown)))
                                    <div class="strategy-list">
                                        @foreach($stat->exit_breakdown as $reason => $count)
                                            <span class="strategy-badge strat-ma">{{ str_replace('_', ' ', $reason) }} {{ round($count / $total * 100) }}%</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="opposing-note">not recorded</span>
                                @endif
                            </td>
                            <td data-label="Counts in Top Picks">
                                @if($stat->qualifies())
                                    <span style="color: var(--accent-green); font-weight: 700;">Yes</span>
                                @elseif($stat->trades < config('screener.min_backtest_trades'))
                                    <span class="opposing-note">No · too few trades</span>
                                @elseif($stat->expectancy_r !== null && $stat->expectancy_r < $minExpectancy)
                                    <span class="opposing-note">No · lost money after costs</span>
                                @else
                                    <span class="opposing-note">No · win rate below {{ $minWinRate }}%</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p style="margin-top: 16px; font-size: 11px; color: var(--text-secondary); line-height: 1.7;">
                <strong>BACKTEST RESULT</strong> — a replay on past data, net of brokerage, STT, exchange and SEBI charges, stamp duty, GST and slippage.
                Not a live or paper-traded result, and not a prediction.
                Figures are in <strong>R</strong>, multiples of the amount risked per trade, so stocks at different prices can be averaged together.
                <strong>Expectancy</strong> is the average R returned per trade: below zero means the strategy lost money over the period tested.
                A strategy whose trades mostly end at <em>session close</em> rather than at its stop or target is being measured on next-day drift rather than on its own plan.
                The whole period was replayed at once with no out-of-sample split, the universe is today's index membership replayed backwards (so delisted stocks are missing), and split/dividend adjustment in the price feed has not been verified.
            </p>
        </div>
    @endif

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
                    <table id="setupsTable" class="card-table">
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
                                <td class="cell-title">
                                    <div class="symbol-badge">{{ $r->symbol }}</div>
                                    <div class="symbol-ns">NSE India</div>
                                </td>
                                <td data-label="Strategy">
                                    <span class="strategy-badge {{ $strategyBadgeClasses[$r->strategy] ?? 'strat-ma' }}">{{ $r->strategy }}</span>
                                </td>
                                <td data-label="Signal">
                                    <span class="signal-pill {{ str_contains($r->signal, 'BUY') ? 'buy' : 'sell' }}">
                                        {{ str_contains($r->signal, 'BUY') ? '▲ BUY' : '▼ SELL' }}
                                    </span>
                                    @if($r->volume_surge)
                                        <span class="volume-badge">Vol Surge</span>
                                    @endif
                                </td>
                                <td class="price-val cell-price" data-label="Entry">₹{{ number_format($r->entry, 2) }}</td>
                                <td class="price-val cell-price" data-label="Stop-Loss" style="color: var(--accent-red);">₹{{ number_format($r->stop_loss, 2) }}</td>
                                <td class="price-val cell-price" data-label="Target" style="color: var(--accent-green);">₹{{ number_format($r->target, 2) }}</td>
                                <td class="price-val" data-label="Live Price" id="live-price-{{ $r->id }}">
                                    <div class="live-price-container">
                                        <span>Loading...</span>
                                        <span class="live-percent">--</span>
                                    </div>
                                </td>
                                <td data-label="Status" id="live-status-{{ $r->id }}">
                                    <span class="status-pill active">Connecting...</span>
                                </td>
                                <td class="cell-block" data-label="Reason">
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
                <h3>📅 Corporate Events Board</h3>
                <p style="font-size:12px; color:var(--text-secondary); margin-bottom:15px; line-height:1.4;">Live NSE board meetings — results, dividends, fund raising and buybacks can move a stock sharply. Click Analyze to run deep technical analysis on any stock.</p>

                @foreach($eventBoard as $label => $day)
                    <div class="calendar-section-title">{{ $label }} ({{ $day['date']->format('D, d M') }})</div>
                    <div class="calendar-list">
                        @forelse($day['events'] as $event)
                            <div class="calendar-item">
                                <div class="calendar-item-info">
                                    <span class="calendar-symbol">{{ $event['symbol'] }}</span>
                                    <span class="calendar-name" title="{{ $event['name'] }} — {{ $event['purpose'] }}">{{ $event['purpose'] }}</span>
                                </div>
                                <button class="calendar-action-btn" onclick="triggerCalendarSearch('{{ $event['symbol'] }}')">Analyze</button>
                            </div>
                        @empty
                            <div style="font-size:12px; color:var(--text-secondary);">No board meetings.</div>
                        @endforelse
                    </div>
                @endforeach
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
    let livePricesLoading = false;
    function fetchLivePrices() {
        // A refresh can outlast the 30s interval; stacking requests blocks the single-threaded dev server
        if (livePricesLoading) return;
        livePricesLoading = true;

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
            .catch(err => console.error("Error fetching live quotes:", err))
            .finally(() => { livePricesLoading = false; });
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
        document.getElementById('tradeNowContainer').style.display = 'none';
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

                // These strategies read completed daily candles, so during the session the
                // signal comes from yesterday's close and is traded at the next open —
                // never at the live price at the top of the panel.
                const setupNote = `<p style="font-size:11px; color:var(--text-secondary); line-height:1.6; margin-bottom:12px;">
                    Based on the close of <strong>${data.signal_close_date}</strong> (₹${data.signal_close.toFixed(2)}).
                    ${data.session_running
                        ? 'Today\'s candle is still forming and is excluded, so these are yesterday\'s signals — the entry is the next session\'s open, not the price above.'
                        : 'The entry is the next session\'s open, not the price above.'}
                </p>`;

                if (data.setups.length === 0) {
                    setupList.innerHTML = setupNote + '<div style="color: var(--text-secondary); font-size: 13px;">No direct strategies triggered on that close.</div>';
                } else {
                    setupList.innerHTML = setupNote + data.setups.map(s => {
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

                // "Can I trade this now?" — verdict against the live price
                const tradeNowContainer = document.getElementById('tradeNowContainer');
                if (data.trade_now) {
                    const tn = data.trade_now;
                    tradeNowContainer.style.display = 'block';

                    const badge = document.getElementById('tradeNowBadge');
                    const isWait = tn.action === 'WAIT';
                    const isBuy = tn.action === 'BUY';
                    badge.innerText = isWait ? '⏸ Wait' : (isBuy ? '▲ Buy now' : '▼ Sell now');
                    badge.style.background = isWait ? 'rgba(255,255,255,0.05)' : (isBuy ? 'var(--glow-green)' : 'var(--glow-red)');
                    badge.style.color = isWait ? 'var(--text-secondary)' : (isBuy ? 'var(--accent-green)' : 'var(--accent-red)');
                    badge.style.border = isWait ? '1px solid var(--border-color)'
                        : (isBuy ? '1px solid rgba(16, 185, 129, 0.3)' : '1px solid rgba(239, 68, 68, 0.3)');

                    document.getElementById('tradeNowHeadline').innerText = isWait
                        ? `No entry at ₹${tn.price.toFixed(2)} (as of ${tn.as_of}) — ${tn.blockers.join('; ')}.`
                        : `All conditions met at ₹${tn.price.toFixed(2)} (as of ${tn.as_of}).`;

                    // One row per rule, so a WAIT always shows which rule blocked it
                    document.getElementById('tradeNowChecks').innerHTML = tn.checks.map(c => `
                        <div style="display: flex; gap: 8px; align-items: flex-start; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 10px 12px; border-radius: 10px;">
                            <span style="color: ${c.pass ? 'var(--accent-green)' : 'var(--accent-red)'}; font-weight: 800;">${c.pass ? '✓' : '✗'}</span>
                            <div>
                                <div style="font-size: 11px; color: var(--text-secondary);">${c.label}</div>
                                <div style="font-size: 13px; color: white;">${c.detail}</div>
                            </div>
                        </div>`).join('');

                    const levels = document.getElementById('tradeNowLevels');
                    const watch = document.getElementById('tradeNowWatch');

                    if (isWait) {
                        levels.style.display = 'none';
                        watch.style.display = 'block';
                        document.getElementById('tradeNowWatchText').innerText = tn.watch || '';
                    } else {
                        watch.style.display = 'none';
                        levels.style.display = 'grid';
                        document.getElementById('tradeNowEntry').innerText = `₹${tn.entry.toFixed(2)}`;
                        document.getElementById('tradeNowStop').innerText = `₹${tn.stop_loss.toFixed(2)} (−${tn.risk_percent.toFixed(2)}%)`;
                        document.getElementById('tradeNowTarget').innerText = `₹${tn.target.toFixed(2)} (+${tn.reward_percent.toFixed(2)}%)`;
                        document.getElementById('tradeNowSquareOff').innerText = tn.square_off_at;
                    }
                } else {
                    tradeNowContainer.style.display = 'none';
                }

                // Live ORB + VWAP Section
                const orbContainer = document.getElementById('orbSetupContainer');
                if (data.orb_setup) {
                    const orb = data.orb_setup;
                    orbContainer.style.display = 'block';
                    document.getElementById('orbReason').innerText = orb.reason;
                    document.getElementById('orbHighVal').innerText = `₹${orb.orb_high.toFixed(2)}`;
                    document.getElementById('orbLowVal').innerText = `₹${orb.orb_low.toFixed(2)}`;
                    document.getElementById('orbTrailVwapVal').innerText = orb.trail_sl === null ? '—' : `₹${orb.trail_sl.toFixed(2)}`;

                    const orbBadge = document.getElementById('orbDecisionBadge');
                    orbBadge.innerText = orb.status;

                    const isTriggered = orb.status === 'Active';
                    if (isTriggered) {
                        const isBuy = orb.type.includes('BUY');
                        orbBadge.style.background = isBuy ? 'var(--glow-green)' : 'var(--glow-red)';
                        orbBadge.style.color = isBuy ? 'var(--accent-green)' : 'var(--accent-red)';
                        orbBadge.style.border = isBuy ? '1px solid rgba(16, 185, 129, 0.3)' : '1px solid rgba(239, 68, 68, 0.3)';
                    } else {
                        orbBadge.style.background = 'rgba(255,255,255,0.05)';
                        orbBadge.style.color = 'var(--text-secondary)';
                        orbBadge.style.border = '1px solid var(--border-color)';
                    }

                    // The breakout is only confirmed once its candle closes, so the entry is
                    // the next candle's open — which may not have printed yet.
                    const orbParams = document.getElementById('orbTradeParameters');
                    const orbPending = document.getElementById('orbPendingNote');

                    if (orb.entry === null) {
                        orbParams.style.display = 'none';
                        orbPending.style.display = 'block';
                    } else {
                        orbParams.style.display = 'grid';
                        orbPending.style.display = 'none';

                        document.getElementById('orbEntryLabel').innerText = `Entry — filled at the ${orb.entry_at} open`;
                        document.getElementById('orbEntryVal').innerText = `₹${orb.entry.toFixed(2)} (${orb.type})`;
                        document.getElementById('orbSlVal').innerText = `₹${orb.stop_loss.toFixed(2)} (${orb.risk_percent.toFixed(2)}% risk)`;
                        document.getElementById('orbTgtVal').innerText = `₹${orb.target.toFixed(2)}`;

                        const sign = orb.pnl >= 0 ? '+' : '';
                        const orbOutcomeVal = document.getElementById('orbOutcomeVal');
                        document.getElementById('orbOutcomeLabel').innerText = orb.is_open
                            ? 'Open P&L since entry'
                            : `Closed ${orb.exit_at} at ₹${orb.exit.toFixed(2)}`;
                        orbOutcomeVal.innerText = `${sign}₹${orb.pnl.toFixed(2)} (${sign}${orb.pnl_percent.toFixed(2)}%)`;
                        orbOutcomeVal.style.color = orb.pnl >= 0 ? 'var(--accent-green)' : 'var(--accent-red)';
                    }
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
                document.getElementById('analysisSetupList').innerHTML = `<div style="color: var(--accent-red); font-size: 14px;">${err.message}</div>`;

                // Clear the placeholders too — otherwise the card sits on "Loading..."
                // and "--" forever, looking like a hang rather than a failed request.
                document.getElementById('analysisPrice').innerText = 'Unavailable';
                document.getElementById('analysisNewsList').innerHTML = '<div style="color: var(--text-secondary); font-size: 13px;">No data — analysis did not complete.</div>';
                ['ma', 'rsi', 'bb', 'macd', 'vol'].forEach(k => {
                    document.getElementById(k + 'Val').innerText = '--';
                    document.getElementById(k + 'Status').innerText = 'No data';
                    document.getElementById(k + 'Detail').innerText = 'Unavailable';
                });
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
