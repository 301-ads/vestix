<?php

namespace Tests\Unit;

use App\Services\AlphaVantageQuoteProvider;
use App\Services\FallbackQuoteProvider;
use App\Services\PolygonQuoteProvider;
use Mockery;
use Tests\TestCase;

class FallbackQuoteProviderTest extends TestCase
{
    public function test_returns_polygon_price_when_available(): void
    {
        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldReceive('fetchLivePrice')->with('PANW')->once()->andReturn(263.22);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchLivePrice');

        $provider = new FallbackQuoteProvider($polygon, $alphaVantage);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
    }

    public function test_falls_back_to_alpha_vantage_when_polygon_returns_null(): void
    {
        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldReceive('fetchLivePrice')->with('PANW')->once()->andReturn(null);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldReceive('fetchLivePrice')->with('PANW')->once()->andReturn(263.22);

        $provider = new FallbackQuoteProvider($polygon, $alphaVantage);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
    }
}
