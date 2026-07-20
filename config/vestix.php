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

    'trade_journal' => [
        'chart_screenshot_max_kb' => (int) env('CHART_SCREENSHOT_MAX_KB', 10240),
    ],

    'strategy_coach' => [
        'min_closed_trades' => (int) env('STRATEGY_COACH_MIN_TRADES', 20),
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
        // Groene bounce net onder SMA 20: voordeel van de twijfel (geen hard fail).
        'trampoline_near_miss_pct' => (float) env('SNIPER_TRAMPOLINE_NEAR_MISS_PCT', 0.25),
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
        'default_mode' => env('SMART_SIZING_DEFAULT_MODE', 'smart'),
        'sector_penalty_per_extra' => (float) env('SMART_SIZING_SECTOR_PENALTY_PER_EXTRA', 0.20),
        'sector_penalty_cap' => (float) env('SMART_SIZING_SECTOR_PENALTY_CAP', 0.90),
    ],
];
