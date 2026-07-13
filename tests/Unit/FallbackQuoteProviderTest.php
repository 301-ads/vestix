<?php

namespace Tests\Unit;

use App\Services\AlphaVantageQuoteProvider;
use App\Services\FallbackQuoteProvider;
use App\Services\FinnhubQuoteProvider;
use App\Services\PolygonQuoteProvider;
use App\Support\PremarketQuoteCapability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class FallbackQuoteProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PremarketQuoteCapability::forgetCachedAssessment();
    }

    private function enablePolygonRealtime(): void
    {
        Cache::put('vestix.premarket_quote_capability', [
            'polygon_realtime' => true,
            'finnhub_intraday' => false,
            'message' => 'Polygon realtime beschikbaar voor pre-market quotes.',
        ], 3600);
    }

    private function disableLivePremarketSources(): void
    {
        Cache::put('vestix.premarket_quote_capability', [
            'polygon_realtime' => false,
            'finnhub_intraday' => false,
            'message' => 'Geen live pre-market bron beschikbaar op het huidige API-plan.',
        ], 3600);
    }

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

    public function test_premarket_price_skips_stale_close_and_uses_polygon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->enablePolygonRealtime();

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 543.50,
            'previous_close' => 557.89,
        ]);

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(543.50, $provider->fetchPremarketPrice('AMD', 557.89));
    }

    public function test_premarket_price_rejects_finnhub_when_it_matches_previous_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->enablePolygonRealtime();

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 557.89,
            'previous_close' => 557.89,
        ]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 557.89,
            'previous_close' => 557.89,
        ]);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 543.50,
        ]);

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(543.50, $provider->fetchPremarketPrice('AMD', 557.89));
    }

    public function test_premarket_price_returns_null_when_all_providers_are_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->enablePolygonRealtime();

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 557.89,
            'previous_close' => 557.89,
        ]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 557.89,
            'previous_close' => 557.89,
        ]);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldReceive('fetchSessionQuote')->with('AMD')->once()->andReturn([
            'close' => 557.89,
        ]);

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $this->assertNull($provider->fetchPremarketPrice('AMD', 557.89));
    }

    public function test_premarket_price_returns_null_when_no_live_source_is_entitled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->disableLivePremarketSources();

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = new FallbackQuoteProvider($finnhub, $alphaVantage, $polygon);

        $this->assertNull($provider->fetchPremarketPrice('AMD', 557.89));
    }
}
