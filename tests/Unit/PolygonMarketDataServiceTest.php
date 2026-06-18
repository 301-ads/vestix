<?php

namespace Tests\Unit;

use App\Contracts\QuoteProvider;
use App\Services\PolygonMarketDataService;
use App\Services\SessionVolumeResolver;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PolygonMarketDataServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        \Tests\Support\MarketDataTestTime::reset();
        Mockery::close();
        parent::tearDown();
    }

    public function test_fetch_for_ticker_builds_full_payload_from_single_polygon_request(): void
    {
        \Tests\Support\MarketDataTestTime::freezeBeforeUsMarketClose();

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => null,
            'vestix.alpha_vantage.api_key' => null,
        ]);

        $bars = [];

        for ($day = 1; $day <= 60; $day++) {
            $close = 100.0 + ($day * 0.1);
            $sessionDate = UsMarketSession::expectedLastCompletedSessionDate()
                ->copy()
                ->subWeekdays(60 - $day);

            $bars[] = [
                'o' => $close - 0.5,
                'h' => $close + 1.0,
                'l' => $close - 1.0,
                'c' => $close,
                'v' => $day === 60 ? 2_000_000 : 1_000_000,
                't' => $sessionDate->timezone('America/New_York')->startOfDay()->timestamp * 1000,
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
        $this->assertCount(14, $payload['recent_close_prices']);
        $this->assertEqualsWithDelta(106.0, end($payload['recent_close_prices']), 0.01);
    }

    public function test_fetch_for_ticker_supplements_stale_polygon_bar_with_quote_provider(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => 'test-finnhub-key',
            'vestix.alpha_vantage.api_key' => 'test-av-key',
        ]);

        $bars = [];

        for ($day = 1; $day <= 60; $day++) {
            $sessionDate = Carbon::parse('2026-06-12', 'America/New_York')->subWeekdays(60 - $day);
            $close = 100.0 + ($day * 0.1);

            $bars[] = [
                'o' => $close - 0.5,
                'h' => $close + 1.0,
                'l' => $close - 1.0,
                'c' => $day === 60 ? 203.36 : $close,
                'v' => 1_000_000,
                't' => $sessionDate->startOfDay()->timestamp * 1000,
            ];
        }

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => $bars,
            ]),
        ]);

        $this->mock(QuoteProvider::class, function ($mock): void {
            $mock->shouldReceive('fetchSessionQuote')
                ->once()
                ->with('TKO')
                ->andReturn([
                    'close' => 201.19,
                    'high' => 204.975,
                    'low' => 199.635,
                    'provider' => 'finnhub',
                ]);
        });

        $this->mock(SessionVolumeResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('TKO', '2026-06-15')
                ->andReturn(5_940_000.0);
        });

        $payload = app(PolygonMarketDataService::class)->fetchForTicker('TKO');

        $this->assertNotNull($payload);
        $this->assertEqualsWithDelta(201.19, $payload['latest_close_price'], 0.001);
        $this->assertTrue(UsMarketSession::isBarStale('2026-06-12'));
    }

    public function test_fetch_for_ticker_confirms_bounce_volume_when_resolver_exceeds_adv30(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => 'test-finnhub-key',
            'vestix.alpha_vantage.api_key' => null,
        ]);

        $bars = [];

        for ($day = 1; $day <= 60; $day++) {
            $sessionDate = Carbon::parse('2026-06-12', 'America/New_York')->subWeekdays(60 - $day);

            $bars[] = [
                'o' => 99.5,
                'h' => 100.5,
                'l' => 99.0,
                'c' => 100.0,
                'v' => 1_000_000,
                't' => $sessionDate->startOfDay()->timestamp * 1000,
            ];
        }

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => $bars,
            ]),
        ]);

        $this->mock(QuoteProvider::class, function ($mock): void {
            $mock->shouldReceive('fetchSessionQuote')
                ->once()
                ->with('ABBV')
                ->andReturn([
                    'close' => 105.0,
                    'high' => 106.0,
                    'low' => 95.0,
                    'provider' => 'finnhub',
                ]);
        });

        $this->mock(SessionVolumeResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('ABBV', '2026-06-15')
                ->andReturn(5_940_000.0);
        });

        $payload = app(PolygonMarketDataService::class)->fetchForTicker('ABBV');

        $this->assertNotNull($payload);
        $this->assertTrue($payload['bounce_volume_above_average']);
        $this->assertEquals(5_940_000, $payload['bounce_day_volume']);
        $this->assertEquals(1_000_000, $payload['avg_volume_30d']);
    }

    public function test_fetch_for_ticker_skips_supplement_when_polygon_bar_is_current(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => 'test-finnhub-key',
            'vestix.alpha_vantage.api_key' => null,
        ]);

        $bars = [];

        for ($day = 1; $day <= 60; $day++) {
            $sessionDate = Carbon::parse('2026-06-15', 'America/New_York')->subWeekdays(60 - $day);
            $close = 100.0 + ($day * 0.1);

            $bars[] = [
                'o' => $close - 0.5,
                'h' => $close + 1.0,
                'l' => $close - 1.0,
                'c' => $close,
                'v' => 5_940_000,
                't' => $sessionDate->startOfDay()->timestamp * 1000,
            ];
        }

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => $bars,
            ]),
        ]);

        $this->mock(QuoteProvider::class, function ($mock): void {
            $mock->shouldNotReceive('fetchSessionQuote');
        });

        $this->mock(SessionVolumeResolver::class, function ($mock): void {
            $mock->shouldNotReceive('resolve');
        });

        $payload = app(PolygonMarketDataService::class)->fetchForTicker('ABBV');

        $this->assertNotNull($payload);
        $this->assertEqualsWithDelta(106.0, $payload['latest_close_price'], 0.01);
        $this->assertFalse(UsMarketSession::needsLatestSessionQuote('2026-06-15'));
    }

    public function test_fetch_for_ticker_returns_null_when_polygon_has_insufficient_bars(): void
    {
        \Tests\Support\MarketDataTestTime::freezeBeforeUsMarketClose();

        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
            'vestix.finnhub.api_key' => null,
            'vestix.alpha_vantage.api_key' => null,
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
