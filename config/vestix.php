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

    'premarket' => [
        'gatekeeper_time' => env('PREMARKET_GATEKEEPER_TIME', '14:30'),
        'gatekeeper_window_start' => env('PREMARKET_GATEKEEPER_WINDOW_START', '14:25'),
        'gatekeeper_window_end' => env('PREMARKET_GATEKEEPER_WINDOW_END', '15:15'),
        'gap_up_threshold_pct' => (float) env('PREMARKET_GAP_UP_THRESHOLD_PCT', 1.0),
        'landing_distance_pct' => (float) env('PREMARKET_LANDING_DISTANCE_PCT', 1.5),
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
];
