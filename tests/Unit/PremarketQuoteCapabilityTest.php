<?php

namespace Tests\Unit;

use App\Support\PremarketQuoteCapability;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PremarketQuoteCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => 'test-finnhub-key',
            'vestix.finnhub.base_url' => 'https://finnhub.io/api/v1',
        ]);

        PremarketQuoteCapability::forgetCachedAssessment();
    }

    public function test_assess_detects_missing_realtime_entitlements(): void
    {
        Http::fake([
            'api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/AMD*' => Http::response([
                'status' => 'NOT_AUTHORIZED',
                'message' => 'You are not entitled to this data.',
            ], 403),
            'finnhub.io/api/v1/stock/candle*' => Http::response([], 403),
        ]);

        $assessment = PremarketQuoteCapability::assess('AMD');

        $this->assertFalse($assessment['polygon_realtime']);
        $this->assertFalse($assessment['finnhub_intraday']);
        $this->assertStringContainsString('Geen live pre-market bron beschikbaar', $assessment['message']);
        $this->assertFalse(PremarketQuoteCapability::hasLivePremarketSource());
    }

    public function test_assess_detects_polygon_realtime_when_snapshot_is_available(): void
    {
        Http::fake([
            'api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/AMD*' => Http::response([
                'status' => 'OK',
                'ticker' => [
                    'lastTrade' => ['p' => 543.50],
                ],
            ]),
            'finnhub.io/api/v1/stock/candle*' => Http::response([], 403),
        ]);

        $assessment = PremarketQuoteCapability::assess('AMD');

        $this->assertTrue($assessment['polygon_realtime']);
        $this->assertTrue(PremarketQuoteCapability::hasLivePremarketSource());
    }

    public function test_assessment_is_cached(): void
    {
        Http::fake([
            'api.polygon.io/v2/snapshot/locale/us/markets/stocks/tickers/AMD*' => Http::response([
                'status' => 'OK',
                'ticker' => [
                    'lastTrade' => ['p' => 543.50],
                ],
            ]),
            'finnhub.io/api/v1/stock/candle*' => Http::response([], 403),
        ]);

        PremarketQuoteCapability::assess('AMD');
        PremarketQuoteCapability::assess('AMD');

        Http::assertSentCount(2);
        $this->assertTrue(Cache::has('vestix.premarket_quote_capability'));
    }
}
