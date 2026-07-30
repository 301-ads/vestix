<?php

return [
    'alpha_vantage' => [
        'api_key' => env('ALPHA_VANTAGE_API_KEY'),
        'base_url' => env('ALPHA_VANTAGE_BASE_URL', 'https://www.alphavantage.co/query'),
        'rate_limit_delay' => (int) env('ALPHA_VANTAGE_RATE_LIMIT_DELAY', 12),
        'intra_request_delay' => (int) env('ALPHA_VANTAGE_INTRA_REQUEST_DELAY', 2),
    ],

    'polygon' => [
        'api_key' => env('POLYGON_API_KEY'),
        'base_url' => env('POLYGON_BASE_URL', 'https://api.polygon.io'),
        // Min seconds between Polygon HTTP calls (free tier: 5/min → 13s incl. marge).
        'rate_limit_delay' => (int) env('POLYGON_RATE_LIMIT_DELAY', 13),
        // Gemiddeld aantal Polygon-calls per positie (bars + volume fallback); voor ETA in bulk sync.
        'estimated_calls_per_position' => (int) env('POLYGON_ESTIMATED_CALLS_PER_POSITION', 2),
    ],

    'finnhub' => [
        'api_key' => env('FINNHUB_API_KEY'),
        'base_url' => env('FINNHUB_BASE_URL', 'https://finnhub.io/api/v1'),
        'rate_limit_delay' => (int) env('FINNHUB_RATE_LIMIT_DELAY', 1),
    ],

    'tradingview' => [
        'symbol_search_url' => env('TRADINGVIEW_SYMBOL_SEARCH_URL', 'https://symbol-search.tradingview.com/symbol_search/'),
        'logo_cdn_url' => env('TRADINGVIEW_LOGO_CDN_URL', 'https://s3-symbol-logo.tradingview.com'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'admin_notification_emails' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('VESTIX_ADMIN_NOTIFICATION_EMAILS', ''))),
        fn (string $email): bool => $email !== '',
    )),

    // Temporarily off — no storage UI yet. Set VESTIX_TRADE_JOURNAL_ENABLED=true to re-enable.
    'trade_journal' => [
        'enabled' => filter_var(env('VESTIX_TRADE_JOURNAL_ENABLED', false), FILTER_VALIDATE_BOOL),
        'chart_screenshot_max_kb' => (int) env('CHART_SCREENSHOT_MAX_KB', 10240),
    ],

    // Temporarily off — keep is_legacy data + tab code. Set VESTIX_LEGACY_ARCHIVE_ENABLED=true to re-enable.
    'legacy_archive' => [
        'enabled' => filter_var(env('VESTIX_LEGACY_ARCHIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    'strategy_coach' => [
        'min_closed_trades' => (int) env('STRATEGY_COACH_MIN_TRADES', 20),
        // Local-only preview of unlocked edge stats (never active outside local).
        'demo_preview' => (bool) env('STRATEGY_COACH_DEMO_PREVIEW', true),
    ],

    'portfolio_coach' => [
        'max_risk_on_per_sector' => (int) env('PORTFOLIO_COACH_MAX_RISK_ON_PER_SECTOR', 1),
        'long_heavy_threshold' => (float) env('PORTFOLIO_COACH_LONG_HEAVY_THRESHOLD', 0.80),
        'short_heavy_threshold' => (float) env('PORTFOLIO_COACH_SHORT_HEAVY_THRESHOLD', 0.80),
    ],

    'scale_out' => [
        'target_1_rr' => (float) env('SCALE_OUT_TARGET_1_RR', 2.0),
        'first_tranche_fraction' => (float) env('SCALE_OUT_FIRST_TRANCHE_FRACTION', 0.5),
        'move_stop_to_breakeven' => (bool) env('SCALE_OUT_MOVE_STOP_TO_BREAKEVEN', true),
    ],

    // Uurlijkse live koersen voor alle open posities (vestix:watch-target-prices).
    'intraday_target_watch' => [
        'enabled' => (bool) env('INTRADAY_TARGET_WATCH_ENABLED', true),
        'window_start' => env('INTRADAY_TARGET_WATCH_WINDOW_START', '04:00'),
        'window_end' => env('INTRADAY_TARGET_WATCH_WINDOW_END', '16:15'),
    ],

    // De Vestix Finnhub -> SPDR ETF Mapping
    'sector_mapping' => [
        'Technology' => 'XLK',
        'Financials' => 'XLF',
        'Healthcare' => 'XLV',
        'Consumer Discretionary' => 'XLY',
        'Consumer Staples' => 'XLP',
        'Energy' => 'XLE',
        'Industrials' => 'XLI',
        'Materials' => 'XLB',
        'Real Estate' => 'XLRE',
        'Communication Services' => 'XLC',
        'Utilities' => 'XLU',
    ],

    // Finnhub profile2 misclassificeert sommige tickers (geen gsector). Override wint van industry_mapping.
    'ticker_sector_overrides' => [
        'SYY' => 'XLP', // Sysco — Food Distributors (Finnhub: Retail → zou XLY worden)
        'USFD' => 'XLP', // US Foods
        'PFGC' => 'XLP', // Performance Food Group
    ],

    // Finnhub profile2 levert vaak finnhubIndustry i.p.v. gsector — map naar sector-ETF.
    'industry_mapping' => [
        'Semiconductors' => 'XLK',
        'Electronic Technology' => 'XLK',
        'Software' => 'XLK',
        'Computer Hardware' => 'XLK',
        'Pharmaceuticals' => 'XLV',
        'Biotechnology' => 'XLV',
        'Health Care' => 'XLV',
        'Health Care Equipment' => 'XLV',
        'Banks' => 'XLF',
        'Financial Services' => 'XLF',
        'Insurance' => 'XLF',
        'Oil & Gas' => 'XLE',
        'Automobiles' => 'XLY',
        'Retail' => 'XLY',
        'Consumer Cyclicals' => 'XLY',
        'Food & Beverage' => 'XLP',
        'Food Distributors' => 'XLP',
        'Food Distribution' => 'XLP',
        'Food Wholesale' => 'XLP',
        'Consumer Defensives' => 'XLP',
        'Consumer Non-Cyclicals' => 'XLP',
        'Industrial Services' => 'XLI',
        'Machinery' => 'XLI',
        'Metals & Mining' => 'XLB',
        'Chemicals' => 'XLB',
        'REITs' => 'XLRE',
        'Real Estate' => 'XLRE',
        'Telecommunications' => 'XLC',
        'Media' => 'XLC',
    ],

    'sniper_scorecard' => [
        'rvol_threshold' => (float) env('SNIPER_RVOL_THRESHOLD', 1.2),
        'extension_atr_threshold' => (float) env('SNIPER_EXTENSION_ATR_THRESHOLD', 2.0),
        'max_points' => 10,
        'sma_slope_lookback_days' => (int) env('SNIPER_SMA_SLOPE_LOOKBACK_DAYS', 10),
        'sma_slope_min_pct' => (float) env('SNIPER_SMA_SLOPE_MIN_PCT', 0.0),
        // Groene bounce net onder SMA 20: voordeel van de twijfel (geen hard fail). Long only.
        'trampoline_near_miss_pct' => (float) env('SNIPER_TRAMPOLINE_NEAR_MISS_PCT', 0.25),
        // Short Route 1: SMA vandaag < 5d < 10d verplicht.
        'waterfall_required' => filter_var(env('SNIPER_WATERFALL_REQUIRED', true), FILTER_VALIDATE_BOOL),
        // Short Route 1: upper wick (High − Open) ≥ ratio × candle body.
        'upper_wick_min_body_ratio' => (float) env('SNIPER_UPPER_WICK_MIN_BODY_RATIO', 1.5),
        // Minimum body floor as % of close so dojis cannot pass the wick check.
        'upper_wick_body_floor_pct' => (float) env('SNIPER_UPPER_WICK_BODY_FLOOR_PCT', 0.1),
    ],

    'premarket' => [
        'gatekeeper_time' => env('PREMARKET_GATEKEEPER_TIME', '14:30'),
        'gatekeeper_window_start' => env('PREMARKET_GATEKEEPER_WINDOW_START', '14:25'),
        'gatekeeper_window_end' => env('PREMARKET_GATEKEEPER_WINDOW_END', '15:15'),
        'gap_up_threshold_pct' => (float) env('PREMARKET_GAP_UP_THRESHOLD_PCT', 1.0),
        'landing_distance_pct' => (float) env('PREMARKET_LANDING_DISTANCE_PCT', 1.5),
    ],

    'market_open_reminder' => [
        // Legacy; digest gebruikt execution_digest.time.
        'time' => env('MARKET_OPEN_REMINDER_TIME', env('EXECUTION_DIGEST_TIME', '15:31')),
    ],

    'execution_digest' => [
        // Pre-market Order Plan prune (onder SMA 20) — vóór prep digest.
        'prune_time' => env('EXECUTION_DIGEST_PRUNE_TIME', '14:30'),
        // Pre-open Stop-Limit plannen (vóór US open 15:30 NL).
        'prep_time' => env('EXECUTION_DIGEST_PREP_TIME', '14:30'),
        // 1 min na US open — Gap Reality Check (overgeslagen Stop-Limits).
        'time' => env('EXECUTION_DIGEST_TIME', '15:31'),
    ],

    // Slippage buffer tussen Buy-Stop en Limit Prijs (Stop-Limit valstrik).
    'stop_limit_buffer' => [
        'tiers' => [
            ['max_price' => 20.0, 'buffer' => 0.05],
            ['max_price' => 50.0, 'buffer' => 0.10],
            ['max_price' => 100.0, 'buffer' => 0.15],
            ['max_price' => null, 'buffer' => 0.25],
        ],
    ],

    'bankroll_tracker' => [
        'benchmark_ticker' => env('BANKROLL_BENCHMARK_TICKER', 'SPY'),
        'update_day' => env('BANKROLL_UPDATE_DAY', 'saturday'),
        'timezone' => 'Europe/Amsterdam',
        'source' => env('BANKROLL_SOURCE', 'manual'),
    ],

    // Read-only IBKR sync (Phase 2). Phase 3 order placement = IbkrExecutionService.
    'ibkr' => [
        'reader' => env('IBKR_READER', 'stub'), // stub | flex
        'expected_base_currency' => env('IBKR_BASE_CURRENCY', 'USD'),
        'stale_after_hours' => (int) env('IBKR_STALE_AFTER_HOURS', 48),
        'block_automation_when_stale' => filter_var(env('IBKR_BLOCK_AUTOMATION_WHEN_STALE', true), FILTER_VALIDATE_BOOL),
        'sync_bankroll_snapshot' => filter_var(env('IBKR_SYNC_BANKROLL_SNAPSHOT', true), FILTER_VALIDATE_BOOL),
        'flex' => [
            'token' => env('IBKR_FLEX_TOKEN'),
            'query_id' => env('IBKR_FLEX_QUERY_ID'),
            // Current IBKR Campus endpoint. Legacy Universal/servlet still works if set explicitly.
            'base_url' => env('IBKR_FLEX_BASE_URL', 'https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService'),
            'user_agent' => env('IBKR_FLEX_USER_AGENT', 'Vestix/1.0'),
            'timeout_seconds' => (int) env('IBKR_FLEX_TIMEOUT', 30),
            'send_request_attempts' => (int) env('IBKR_FLEX_SEND_REQUEST_ATTEMPTS', 3),
            'poll_attempts' => (int) env('IBKR_FLEX_POLL_ATTEMPTS', 8),
            'poll_delay_ms' => (int) env('IBKR_FLEX_POLL_DELAY_MS', 1500),
        ],
        'client_portal' => [
            'enabled' => filter_var(env('IBKR_CP_ENABLED', false), FILTER_VALIDATE_BOOL),
            'base_url' => env('IBKR_CP_BASE_URL', 'https://localhost:5000'),
            'timeout_seconds' => (int) env('IBKR_CP_TIMEOUT', 15),
        ],
        'cashflow' => [
            'allowlist' => [
                'Deposits & Withdrawals',
                'Deposits/Withdrawals', // Activity Flex uses a slash
                'Deposit',
                'Withdrawal',
                'Electronic Fund Transfer',
                'Deposits',
                'Withdrawals',
            ],
            // EUR (and optionally others) bank deposits are converted to base USD via fxRateToBase.
            'foreign_deposit_currencies' => ['EUR'],
            'denylist' => [
                'Dividend',
                'Payment In Lieu',
                'Payment in Lieu',
                'Withholding Tax',
                'Broker Interest',
                'Advisor Fee',
                'Commission',
                'Other Fees',
                'Bond Interest',
            ],
        ],
        'stub' => [
            'net_liquidation' => env('IBKR_STUB_NLV'),
            'available_funds' => env('IBKR_STUB_AVAILABLE_FUNDS'),
            'settled_cash' => env('IBKR_STUB_SETTLED_CASH'),
            'open_positions' => [],
            'open_orders' => [],
        ],
    ],

    'brokers' => [
        'revolut' => [
            'stock_url' => env('REVOLUT_STOCK_URL_TEMPLATE', 'https://www.revolut.com/app-invest/stocks/{ticker}'),
        ],
        'ibkr' => [
            'stock_url' => env('IBKR_STOCK_URL_TEMPLATE'),
        ],
    ],

    'overbought_trailing' => [
        'rsi_threshold' => (float) env('OVERBOUGHT_TRAILING_RSI_THRESHOLD', 70),
        'atr_multiplier' => (float) env('OVERBOUGHT_TRAILING_ATR_MULTIPLIER', 1.5),
    ],

    'pre_earnings_trailing' => [
        'window_days' => (int) env('PRE_EARNINGS_TRAILING_WINDOW_DAYS', 14),
        'sma_extension_pct' => (float) env('PRE_EARNINGS_TRAILING_SMA_EXTENSION_PCT', 5.0),
        'aggressive_method' => env('PRE_EARNINGS_TRAILING_AGGRESSIVE_METHOD', 'atr'),
        'atr_multiplier' => (float) env('PRE_EARNINGS_TRAILING_ATR_MULTIPLIER', 1.5),
        'prior_day_buffer_pct' => (float) env('PRE_EARNINGS_TRAILING_PRIOR_DAY_BUFFER_PCT', 0.1),
    ],

    'smart_sizing' => [
        'min_score' => (int) env('SMART_SIZING_MIN_SCORE', 5),
        'min_quantity' => (int) env('SMART_SIZING_MIN_QUANTITY', 2),
        'default_mode' => env('SMART_SIZING_DEFAULT_MODE', 'smart'),
        'sector_penalty_per_extra' => (float) env('SMART_SIZING_SECTOR_PENALTY_PER_EXTRA', 0.20),
        'sector_penalty_cap' => (float) env('SMART_SIZING_SECTOR_PENALTY_CAP', 0.90),
    ],

    'kluis' => [
        'default_etf_ticker' => env('VESTIX_KLUIS_ETF_TICKER', 'VWCE'),
        'default_monthly_budget' => (float) env('VESTIX_KLUIS_MONTHLY_BUDGET', 10000),
        'overheat_threshold_pct' => (float) env('VESTIX_KLUIS_OVERHEAT_PCT', 10),
        'crash_threshold_pct' => (float) env('VESTIX_KLUIS_CRASH_PCT', 10),
        'overheat_invest_fraction' => (float) env('VESTIX_KLUIS_OVERHEAT_INVEST_FRACTION', 0.5),
        'dip_dry_powder_fraction' => (float) env('VESTIX_KLUIS_DIP_DRY_POWDER_FRACTION', 0.25),
        'crash_dry_powder_fraction' => (float) env('VESTIX_KLUIS_CRASH_DRY_POWDER_FRACTION', 0.5),
        'cache_ttl_seconds' => (int) env('VESTIX_KLUIS_CACHE_TTL', 93600), // ~26u: nachtelijke EOD-refresh blijft overdag geldig
        'bar_lookback_days' => (int) env('VESTIX_KLUIS_BAR_LOOKBACK_DAYS', 320),
        'bar_limit' => (int) env('VESTIX_KLUIS_BAR_LIMIT', 250),
        // US-listed proxies for EU ETFs (Polygon free tier has no VWCE).
        // Display ticker stays VWCE; thermometer climate uses this market-data symbol.
        'thermometer_proxies' => [
            'VWCE' => env('VESTIX_KLUIS_THERMOMETER_VWCE', 'VT'),
        ],
        'polygon_tickers' => [
            'VWCE' => env('VESTIX_KLUIS_POLYGON_VWCE', 'VT'),
        ],
        'finnhub_symbols' => [
            'VWCE' => env('VESTIX_KLUIS_FINNHUB_VWCE', 'VWCE.DE'),
        ],
        // Holdings MTM must use EUR (broker) symbols — never the USD thermometer proxy.
        'holdings_price_symbols' => [
            'VWCE' => array_values(array_filter([
                env('VESTIX_KLUIS_HOLDINGS_VWCE', 'VWCE.DE'),
                'VWCE',
            ])),
        ],
    ],

    /*
    | Native EOD Sniper Scanner (Architectuur V3).
    | Default ON. Disable with VESTIX_SNIPER_SCANNER_ENABLED=false, or git/Forge rollback.
    */
    'sniper_scanner' => [
        'enabled' => filter_var(env('VESTIX_SNIPER_SCANNER_ENABLED', true), FILTER_VALIDATE_BOOL),
        'owner_user_id' => (int) env('VESTIX_SNIPER_OWNER_USER_ID', 0),
        'earnings_cutoff_days' => (int) env('VESTIX_SNIPER_EARNINGS_CUTOFF_DAYS', 14),
        'max_earnings_checks_per_run' => (int) env('VESTIX_SNIPER_MAX_EARNINGS_CHECKS', 50),
        'min_volume' => (int) env('VESTIX_SNIPER_MIN_VOLUME', 1_000_000),
        'min_avg_volume_30d' => (int) env('VESTIX_SNIPER_MIN_AVG_VOLUME_30D', 1_000_000),
        'min_market_cap' => (float) env('VESTIX_SNIPER_MIN_MARKET_CAP', 2_000_000_000),
        // Morning after US session: Polygon Basic Grouped Daily often 403's same evening.
        'schedule_time' => env('VESTIX_SNIPER_SCAN_TIME', '06:30'),
        // Warm market caps the evening before the morning scan.
        'profile_refresh_time' => env('VESTIX_SNIPER_PROFILE_REFRESH_TIME', '20:00'),
        'split_gap_pct' => (float) env('VESTIX_SNIPER_SPLIT_GAP_PCT', 40.0),
        'bars_retention_days' => (int) env('VESTIX_SNIPER_BARS_RETENTION_DAYS', 90),
        'min_bars_for_ready' => (int) env('VESTIX_SNIPER_MIN_BARS_FOR_READY', 50),
        'profile_refresh_per_run' => (int) env('VESTIX_SNIPER_PROFILE_REFRESH_PER_RUN', 150),
        'etf_allowlist' => array_values(array_filter(array_map(
            static fn (string $ticker): string => strtoupper(trim($ticker)),
            explode(',', (string) env('VESTIX_SNIPER_ETF_ALLOWLIST', 'SPY,QQQ,IWM,SMH')),
        ), static fn (string $ticker): bool => $ticker !== '')),
    ],

    /*
    | Free-First entitlements. Overrides force a feature on/off for all users.
    | Paid plans can later set ui_preferences.plan without rewriting the free loop.
    */
    'entitlements' => [
        'overrides' => [
            // 'sniper_scanner' => true,
        ],
    ],
];
