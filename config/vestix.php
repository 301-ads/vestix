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
        'rate_limit_delay' => (int) env('POLYGON_RATE_LIMIT_DELAY', 12),
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
];
