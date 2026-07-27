<?php

namespace Tests\Unit\Kluis;

use App\Contracts\DailyBarProvider;
use App\Contracts\QuoteProvider;
use App\Models\User;
use App\Models\VaultSetting;
use App\Services\Kluis\KluisMarketDataService;
use App\Services\Kluis\KluisThermometer;
use App\Services\YahooFinanceChartQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class KluisMarketDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(DailyBarProvider $bars, ?QuoteProvider $quotes = null, ?YahooFinanceChartQuoteService $yahoo = null): KluisMarketDataService
    {
        $quotes ??= Mockery::mock(QuoteProvider::class);
        $yahoo ??= Mockery::mock(YahooFinanceChartQuoteService::class);
        $yahoo->shouldReceive('fetchLivePrice')->andReturn(null)->byDefault();

        return new KluisMarketDataService($bars, $quotes, $yahoo, new KluisThermometer);
    }

    public function test_returns_null_when_fewer_than_200_bars(): void
    {
        $bars = [];
        for ($i = 0; $i < 50; $i++) {
            $bars[] = [
                'open' => 100,
                'high' => 101,
                'low' => 99,
                'close' => 100 + ($i * 0.01),
                'volume' => 1000,
                'date' => now()->subDays(50 - $i)->toDateString(),
            ];
        }

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1000.0,
            'bars' => $bars,
        ]);

        $this->app->instance(DailyBarProvider::class, $provider);

        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->save();

        $reading = app(KluisMarketDataService::class)->fetchReading($settings->fresh(), force: true);

        $this->assertNull($reading);
    }

    public function test_computes_sma_200_from_bars(): void
    {
        Cache::flush();

        $bars = [];
        for ($i = 0; $i < 220; $i++) {
            $bars[] = [
                'open' => 100,
                'high' => 101,
                'low' => 99,
                'close' => 100.0,
                'volume' => 1000,
                'date' => now()->subDays(220 - $i)->toDateString(),
            ];
        }
        $bars[219]['close'] = 110.0;

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')
            ->once()
            ->andReturn([
                'today' => $bars[219],
                'adv30' => 1000.0,
                'bars' => $bars,
            ]);

        $service = $this->makeService($provider);

        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->save();

        $reading = $service->fetchReading($settings->fresh(), force: true);

        $this->assertNotNull($reading);
        $this->assertEqualsWithDelta(110.0, $reading->close, 0.01);
        $this->assertEqualsWithDelta(100.05, $reading->sma200, 0.01);
        $this->assertTrue($reading->deviationPct > 9.0);
    }

    public function test_skips_provider_when_not_forced_and_cache_empty(): void
    {
        Cache::flush();

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldNotReceive('fetchRecentBars');

        $service = $this->makeService($provider);

        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->save();

        $this->assertNull($service->fetchReading($settings->fresh(), force: false));
    }

    public function test_candidate_symbols_prefer_mapped_providers(): void
    {
        $symbols = app(KluisMarketDataService::class)->candidateSymbols('VWCE');

        $this->assertSame(['VT', 'VWCE', 'VWCE.DE'], $symbols);
    }

    public function test_holdings_price_symbols_exclude_thermometer_proxy(): void
    {
        $symbols = app(KluisMarketDataService::class)->holdingsPriceSymbols('VWCE');

        $this->assertSame(['VWCE.DE', 'VWCE'], $symbols);
        $this->assertNotContains('VT', $symbols);
    }

    public function test_fetch_holdings_price_uses_eur_quote_not_proxy(): void
    {
        Cache::flush();

        $yahoo = Mockery::mock(YahooFinanceChartQuoteService::class);
        $yahoo->shouldReceive('fetchLivePrice')
            ->once()
            ->with('VWCE.DE')
            ->andReturn(165.14);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldNotReceive('fetchLivePrice');

        $bars = Mockery::mock(DailyBarProvider::class);
        $service = $this->makeService($bars, $quotes, $yahoo);

        $payload = $service->fetchHoldingsPrice('VWCE', force: true);

        $this->assertNotNull($payload);
        $this->assertEqualsWithDelta(165.14, $payload['price'], 0.01);
        $this->assertSame('VWCE.DE', $payload['resolved_symbol']);

        $cached = $service->fetchHoldingsPrice('VWCE', force: false);
        $this->assertNotNull($cached);
        $this->assertEqualsWithDelta(165.14, $cached['price'], 0.01);
    }

    public function test_fetch_holdings_price_falls_back_when_yahoo_missing(): void
    {
        Cache::flush();

        $yahoo = Mockery::mock(YahooFinanceChartQuoteService::class);
        $yahoo->shouldReceive('fetchLivePrice')->andReturn(null);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchLivePrice')
            ->once()
            ->with('VWCE.DE')
            ->andReturn(164.28);

        $bars = Mockery::mock(DailyBarProvider::class);
        $service = $this->makeService($bars, $quotes, $yahoo);

        $payload = $service->fetchHoldingsPrice('VWCE', force: true);

        $this->assertNotNull($payload);
        $this->assertEqualsWithDelta(164.28, $payload['price'], 0.01);
    }

    public function test_force_bypasses_cache_and_refetches(): void
    {
        Cache::flush();

        $bars = [];
        for ($i = 0; $i < 220; $i++) {
            $bars[] = [
                'open' => 100,
                'high' => 101,
                'low' => 99,
                'close' => 100.0,
                'volume' => 1000,
                'date' => now()->subDays(220 - $i)->toDateString(),
            ];
        }
        $bars[219]['close'] = 110.0;

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')
            ->twice()
            ->andReturn([
                'today' => $bars[219],
                'adv30' => 1000.0,
                'bars' => $bars,
            ]);

        $service = $this->makeService($provider);

        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->save();

        $first = $service->fetchReading($settings->fresh(), force: true);
        $this->assertNotNull($first);

        $second = $service->fetchReading($settings->fresh(), force: true);
        $this->assertNotNull($second);
        $this->assertEqualsWithDelta(110.0, $second->close, 0.01);
    }

    public function test_without_force_returns_cached_reading(): void
    {
        Cache::flush();

        $bars = [];
        for ($i = 0; $i < 220; $i++) {
            $bars[] = [
                'open' => 100,
                'high' => 101,
                'low' => 99,
                'close' => 100.0,
                'volume' => 1000,
                'date' => now()->subDays(220 - $i)->toDateString(),
            ];
        }
        $bars[219]['close'] = 110.0;

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')
            ->once()
            ->andReturn([
                'today' => $bars[219],
                'adv30' => 1000.0,
                'bars' => $bars,
            ]);

        $service = $this->makeService($provider);

        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->save();

        $this->assertNotNull($service->fetchReading($settings->fresh(), force: true));
        $cached = $service->fetchReading($settings->fresh(), force: false);
        $this->assertNotNull($cached);
        $this->assertEqualsWithDelta(110.0, $cached->close, 0.01);
    }
}
