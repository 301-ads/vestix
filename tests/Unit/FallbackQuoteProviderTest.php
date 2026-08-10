<?php

namespace Tests\Unit;

use App\Services\AlphaVantageQuoteProvider;
use App\Services\FallbackQuoteProvider;
use App\Services\FinnhubQuoteProvider;
use App\Services\PolygonQuoteProvider;
use App\Services\TradingViewPremarketQuoteService;
use App\Services\YahooFinanceChartQuoteService;
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
            'tradingview_scanner' => true,
            'message' => 'Polygon realtime beschikbaar voor pre-market quotes.',
        ], 3600);
    }

    private function disablePaidLivePremarketSources(): void
    {
        Cache::put('vestix.premarket_quote_capability', [
            'polygon_realtime' => false,
            'finnhub_intraday' => false,
            'tradingview_scanner' => true,
            'message' => 'TradingView scanner beschikbaar voor pre-market quotes.',
        ], 3600);
    }

    private function tradingViewMock(): TradingViewPremarketQuoteService
    {
        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')->andReturn(['ok' => false, 'price' => null])->byDefault();
        $tv->shouldReceive('fetchPremarketPrice')->andReturn(null)->byDefault();

        return $tv;
    }

    private function yahooMock(): YahooFinanceChartQuoteService
    {
        $yahoo = Mockery::mock(YahooFinanceChartQuoteService::class);
        $yahoo->shouldReceive('fetchExtendedHoursLastPrice')->andReturn(null)->byDefault();
        $yahoo->shouldReceive('fetchLivePrice')->andReturn(null)->byDefault();

        return $yahoo;
    }

    private function makeProvider(
        FinnhubQuoteProvider $finnhub,
        AlphaVantageQuoteProvider $alphaVantage,
        PolygonQuoteProvider $polygon,
        ?TradingViewPremarketQuoteService $tv = null,
        ?YahooFinanceChartQuoteService $yahoo = null,
    ): FallbackQuoteProvider {
        return new FallbackQuoteProvider(
            $finnhub,
            $alphaVantage,
            $polygon,
            $tv ?? $this->tradingViewMock(),
            $yahoo ?? $this->yahooMock(),
        );
    }

    public function test_returns_finnhub_price_when_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 11:00:00', 'America/New_York'));

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

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));

        Carbon::setTestNow();
    }

    public function test_falls_back_to_alpha_vantage_when_finnhub_returns_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 11:00:00', 'America/New_York'));

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

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(263.22, $provider->fetchLivePrice('PANW'));

        Carbon::setTestNow();
    }

    public function test_falls_back_to_polygon_when_finnhub_and_alpha_vantage_return_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 11:00:00', 'America/New_York'));

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

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon);

        $quote = $provider->fetchSessionQuoteWithProvider('PANW');

        $this->assertNotNull($quote);
        $this->assertSame(263.22, $quote['close']);
        $this->assertSame('polygon', $quote['provider']);

        Carbon::setTestNow();
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

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon);

        $this->assertSame(543.50, $provider->fetchPremarketPrice('AMD', 557.89));
    }

    public function test_premarket_uses_tradingview_when_paid_sources_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->disablePaidLivePremarketSources();

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('PECO')
            ->andReturn(['ok' => true, 'price' => 42.40]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

        $this->assertSame(42.40, $provider->fetchPremarketPrice('PECO', 42.79));
    }

    public function test_premarket_keeps_unavailable_when_tradingview_reports_no_eh_trades(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->disablePaidLivePremarketSources();

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('GNTX')
            ->andReturn(['ok' => true, 'price' => null]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        // Must not reintroduce RTH close after TV says no premarket trades.
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

        $this->assertNull($provider->fetchPremarketPrice('GNTX', 23.97));
    }

    public function test_premarket_rejects_stale_rth_close_equal_to_reference(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->disablePaidLivePremarketSources();

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('GNTX')
            ->andReturn(['ok' => false, 'price' => null]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldReceive('fetchSessionQuote')->with('GNTX')->once()->andReturn([
            'close' => 23.97,
            'previous_close' => 24.05,
            'quoted_at' => Carbon::parse('2026-07-12 16:00:00', 'America/New_York'),
        ]);

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldReceive('fetchSessionQuote')->with('GNTX')->once()->andReturn([
            'close' => 23.97,
        ]);

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

        $this->assertNull($provider->fetchPremarketPrice('GNTX', 23.97));
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

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('AMD')
            ->andReturn(['ok' => true, 'price' => 543.50]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

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

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('AMD')
            ->andReturn(['ok' => true, 'price' => 557.89]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

        $this->assertNull($provider->fetchPremarketPrice('AMD', 557.89));
    }

    public function test_premarket_accepts_real_print_one_cent_from_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->disablePaidLivePremarketSources();

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('PECO')
            ->andReturn(['ok' => true, 'price' => 42.80]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');
        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');
        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

        // Must not treat float noise on abs(42.80-42.79) as a stale RTH close.
        $this->assertSame(42.80, $provider->fetchPremarketPrice('PECO', 42.79));
    }

    public function test_premarket_price_uses_tradingview_when_no_paid_realtime_entitlement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:30:00', 'America/New_York'));
        $this->disablePaidLivePremarketSources();

        $tv = Mockery::mock(TradingViewPremarketQuoteService::class);
        $tv->shouldReceive('fetchPremarketQuote')
            ->once()
            ->with('AMD')
            ->andReturn(['ok' => true, 'price' => 543.50]);

        $finnhub = Mockery::mock(FinnhubQuoteProvider::class);
        $finnhub->shouldNotReceive('fetchSessionQuote');

        $alphaVantage = Mockery::mock(AlphaVantageQuoteProvider::class);
        $alphaVantage->shouldNotReceive('fetchSessionQuote');

        $polygon = Mockery::mock(PolygonQuoteProvider::class);
        $polygon->shouldNotReceive('fetchSessionQuote');

        $provider = $this->makeProvider($finnhub, $alphaVantage, $polygon, $tv);

        $this->assertSame(543.50, $provider->fetchPremarketPrice('AMD', 557.89));
    }
}
