<?php

namespace Tests\Unit;

use App\Services\PolygonQuoteProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PolygonQuoteProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.polygon.rate_limit_delay' => 0,
        ]);

        \App\Support\PremarketQuoteCapability::forgetCachedAssessment();
        Cache::put('vestix.premarket_quote_capability', [
            'polygon_realtime' => true,
            'finnhub_intraday' => false,
            'message' => 'Polygon realtime beschikbaar voor pre-market quotes.',
        ], 3600);
    }

    public function test_fetch_live_price_returns_price_from_snapshot_last_trade(): void
    {
        Http::fake([
            'api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/PANW*' => Http::response([
                'status' => 'OK',
                'ticker' => [
                    'lastTrade' => ['p' => 263.22],
                    'prevDay' => ['c' => 260.00],
                    'day' => ['o' => 261.00, 'h' => 264.00, 'l' => 260.50],
                ],
            ]),
        ]);

        $provider = app(PolygonQuoteProvider::class);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
        Http::assertSentCount(1);
    }

    public function test_fetch_live_price_falls_back_to_last_trade_when_snapshot_missing(): void
    {
        Http::fake([
            'api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/PANW*' => Http::response([
                'status' => 'OK',
                'ticker' => [],
            ]),
            'api.polygon.io/v2/last/trade/PANW*' => Http::response([
                'status' => 'OK',
                'results' => ['p' => 263.22],
            ]),
        ]);

        $provider = app(PolygonQuoteProvider::class);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
    }

    public function test_fetch_live_price_returns_null_without_api_key(): void
    {
        config(['vestix.polygon.api_key' => null]);

        Http::fake();

        $provider = app(PolygonQuoteProvider::class);

        $this->assertNull($provider->fetchLivePrice('PANW'));
        Http::assertNothingSent();
    }

    public function test_fetch_live_price_returns_null_on_api_error(): void
    {
        Http::fake([
            'api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/PANW*' => Http::response([
                'status' => 'ERROR',
                'error' => 'Invalid API Key',
            ]),
            'api.polygon.io/v2/last/trade/PANW*' => Http::response([
                'status' => 'ERROR',
                'error' => 'Invalid API Key',
            ]),
        ]);

        $provider = app(PolygonQuoteProvider::class);

        $this->assertNull($provider->fetchLivePrice('PANW'));
    }
}
