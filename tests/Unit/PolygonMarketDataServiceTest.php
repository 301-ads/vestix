<?php

namespace Tests\Unit;

use App\Services\PolygonMarketDataService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PolygonMarketDataServiceTest extends TestCase
{
    public function test_fetch_for_ticker_builds_full_payload_from_single_polygon_request(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $bars = [];

        for ($day = 1; $day <= 60; $day++) {
            $close = 100.0 + ($day * 0.1);
            $bars[] = [
                'o' => $close - 0.5,
                'h' => $close + 1.0,
                'l' => $close - 1.0,
                'c' => $close,
                'v' => $day === 60 ? 2_000_000 : 1_000_000,
                't' => now()->subDays(60 - $day)->startOfDay()->timestamp * 1000,
            ];
        }

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => $bars,
            ]),
        ]);

        $payload = app(PolygonMarketDataService::class)->fetchForTicker('APTV');

        $this->assertNotNull($payload);
        $this->assertEqualsWithDelta(106.0, $payload['latest_close_price'], 0.01);
        $this->assertNotNull($payload['latest_sma_20']);
        $this->assertNotNull($payload['latest_sma_50']);
        $this->assertNotNull($payload['latest_atr_14']);
        $this->assertNotNull($payload['scout_rsi']);
        $this->assertEquals(1_000_000, $payload['avg_volume_30d']);
    }

    public function test_fetch_for_ticker_returns_null_when_polygon_has_insufficient_bars(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'o' => 100,
                        'h' => 101,
                        'l' => 99,
                        'c' => 100.5,
                        'v' => 1_000_000,
                        't' => now()->timestamp * 1000,
                    ],
                ],
            ]),
        ]);

        $this->assertNull(app(PolygonMarketDataService::class)->fetchForTicker('APTV'));
    }
}
