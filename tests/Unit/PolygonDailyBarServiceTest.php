<?php

namespace Tests\Unit;

use App\Services\PolygonDailyBarService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PolygonDailyBarServiceTest extends TestCase
{
    public function test_is_bounce_day_when_low_below_sma_and_close_above_sma(): void
    {
        $this->assertTrue(PolygonDailyBarService::isBounceDay(99.0, 101.0, 100.0));
    }

    public function test_is_not_bounce_day_when_close_below_sma(): void
    {
        $this->assertFalse(PolygonDailyBarService::isBounceDay(99.0, 98.0, 100.0));
    }

    public function test_is_not_bounce_day_when_low_stays_above_sma(): void
    {
        $this->assertFalse(PolygonDailyBarService::isBounceDay(101.0, 103.0, 100.0));
    }

    public function test_fetch_recent_bars_calculates_adv30_from_prior_sessions(): void
    {
        config([
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
        ]);

        $bars = [];

        for ($day = 1; $day <= 31; $day++) {
            $bars[] = [
                'o' => 100,
                'h' => 101,
                'l' => 99,
                'c' => 100.5,
                'v' => $day === 31 ? 5_000_000 : 1_000_000,
                't' => now()->subDays(31 - $day)->startOfDay()->timestamp * 1000,
            ];
        }

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => $bars,
            ]),
        ]);

        $result = app(PolygonDailyBarService::class)->fetchRecentBars('APTV');

        $this->assertNotNull($result);
        $this->assertEquals(5_000_000, $result['today']['volume']);
        $this->assertEquals(1_000_000, $result['adv30']);
    }
}
