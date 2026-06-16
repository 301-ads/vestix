<?php

namespace Tests\Unit;

use App\Services\SessionVolumeResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SessionVolumeResolverTest extends TestCase
{
    public function test_resolve_returns_finnhub_volume_for_session_date(): void
    {
        config([
            'vestix.finnhub.api_key' => 'test-finnhub-key',
            'vestix.finnhub.base_url' => 'https://finnhub.test',
            'vestix.polygon.api_key' => null,
            'vestix.alpha_vantage.api_key' => null,
        ]);

        Http::fake([
            'finnhub.test/*' => Http::response([
                's' => 'ok',
                't' => [
                    strtotime('2026-06-12 00:00:00 America/New_York'),
                    strtotime('2026-06-15 00:00:00 America/New_York'),
                ],
                'o' => [100.0, 100.0],
                'h' => [101.0, 106.0],
                'l' => [99.0, 95.0],
                'c' => [100.5, 105.0],
                'v' => [1_000_000, 5_940_000],
            ]),
        ]);

        $volume = app(SessionVolumeResolver::class)->resolve('ABBV', '2026-06-15');

        $this->assertEquals(5_940_000.0, $volume);
    }

    public function test_resolve_falls_back_to_polygon_when_finnhub_unavailable(): void
    {
        config([
            'vestix.finnhub.api_key' => null,
            'vestix.polygon.api_key' => 'test-polygon-key',
            'vestix.polygon.base_url' => 'https://api.polygon.io',
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
                        't' => strtotime('2026-06-12 00:00:00 America/New_York') * 1000,
                    ],
                    [
                        'o' => 100,
                        'h' => 106,
                        'l' => 95,
                        'c' => 105,
                        'v' => 5_940_000,
                        't' => strtotime('2026-06-15 00:00:00 America/New_York') * 1000,
                    ],
                ],
            ]),
        ]);

        $volume = app(SessionVolumeResolver::class)->resolve('ABBV', '2026-06-15');

        $this->assertEquals(5_940_000.0, $volume);
    }
}
