<?php

namespace Tests\Unit;

use App\Services\AlphaVantageQuoteProvider;
use App\Services\FallbackQuoteProvider;
use App\Services\FinnhubQuoteProvider;
use App\Services\PolygonQuoteProvider;
use Mockery;
use Tests\TestCase;

class FallbackQuoteProviderTest extends TestCase
{
    public function test_returns_finnhub_price_when_available(): void
    {
        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldReceive('fetchSessionQuote')->with('PANW')->once()->andReturn([
            'close' => 263.22,
            'high' => 264.0,
            'low' => 262.0,
        ]);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
    }

    public function test_falls_back_to_alpha_vantage_when_finnhub_returns_null(): void
    {
        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldReceive('fetchSessionQuote')->with('PANW')->once()->andReturn(null);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldReceive('fetchSessionQuote')->with('PANW')->once()->andReturn([
            'close' => 263.22,
            'high' => 264.0,
            'low' => 262.0,
        ]);

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));
    }

    public function test_falls_back_to_polygon_when_finnhub_and_alpha_vantage_return_null(): void
    {
        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldReceive('fetchSessionQuote')->with('PANW')->once()->andReturn(null);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldReceive('fetchSessionQuote')->with('PANW')->once()->andReturn(null);

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldReceive('fetchSessionQuote')->with('PANW')->once()->andReturn([
            'close' => 263.22,
            'high' => null,
            'low' => null,
        ]);

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $quote = $provider->fetchSessionQuoteWithProvider('PANW');

        $this->assertNotNull($quote);
        $this->assertSame(263.22, $quote['close']);
        $this->assertSame('polygon', $quote['provider']);
    }
}
