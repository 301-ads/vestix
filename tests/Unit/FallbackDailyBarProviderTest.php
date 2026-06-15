<?php

namespace Tests\Unit;

use App\Services\AlphaVantageDailyBarService;
use App\Services\FallbackDailyBarProvider;
use App\Services\FinnhubDailyBarService;
use App\Services\PolygonDailyBarService;
use Mockery;
use Tests\TestCase;

class FallbackDailyBarProviderTest extends TestCase
{
    public function test_returns_polygon_bars_when_available(): void
    {
        $bars = [
            'today' => ['open' => 1, 'high' => 2, 'low' => 0.5, 'close' => 1.5, 'volume' => 100],
            'adv30' => 100,
            'bars' => [],
        ];

        $polygon = Mockery::mock(PolygonDailyBarService::class);
        $polygon->shouldReceive('fetchRecentBars')->with('TKO', 90, 120)->once()->andReturn($bars);

        $finnhub = Mockery::mock(FinnhubDailyBarService::class);
        $finnhub->shouldNotReceive('fetchRecentBars');

        $alphaVantage = Mockery::mock(AlphaVantageDailyBarService::class);
        $alphaVantage->shouldNotReceive('fetchRecentBars');

        $provider = new FallbackDailyBarProvider($polygon, $finnhub, $alphaVantage);

        $this->assertSame($bars, $provider->fetchRecentBars('TKO', 90, 120));
    }

    public function test_falls_back_to_finnhub_when_polygon_returns_null(): void
    {
        $bars = [
            'today' => ['open' => 1, 'high' => 2, 'low' => 0.5, 'close' => 1.5, 'volume' => 100],
            'adv30' => 100,
            'bars' => [],
        ];

        $polygon = Mockery::mock(PolygonDailyBarService::class);
        $polygon->shouldReceive('fetchRecentBars')->with('TKO', 90, 120)->once()->andReturn(null);

        $finnhub = Mockery::mock(FinnhubDailyBarService::class);
        $finnhub->shouldReceive('fetchRecentBars')->with('TKO', 90, 120)->once()->andReturn($bars);

        $alphaVantage = Mockery::mock(AlphaVantageDailyBarService::class);
        $alphaVantage->shouldNotReceive('fetchRecentBars');

        $provider = new FallbackDailyBarProvider($polygon, $finnhub, $alphaVantage);

        $this->assertSame($bars, $provider->fetchRecentBars('TKO', 90, 120));
    }
}
