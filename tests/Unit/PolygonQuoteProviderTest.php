<?php

namespace Tests\Unit;

use App\Services\PolygonQuoteProvider;
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
        ]);
    }

    public function test_fetch_live_price_returns_price_from_last_trade(): void
    {
        Http::fake([
            'api.polygon.io/v2/last/trade/PANW*' => Http::response([
                'status' => 'OK',
                'results' => ['p' => 263.22],
            ]),
        ]);

        $provider = new PolygonQuoteProvider;

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
    }

    public function test_fetch_live_price_returns_null_without_api_key(): void
    {
        config(['vestix.polygon.api_key' => null]);

        Http::fake();

        $provider = new PolygonQuoteProvider;

        $this->assertNull($provider->fetchLivePrice('PANW'));
        Http::assertNothingSent();
    }

    public function test_fetch_live_price_returns_null_on_api_error(): void
    {
        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'ERROR',
                'error' => 'Invalid API Key',
            ]),
        ]);

        $provider = new PolygonQuoteProvider;

        $this->assertNull($provider->fetchLivePrice('PANW'));
    }
}
