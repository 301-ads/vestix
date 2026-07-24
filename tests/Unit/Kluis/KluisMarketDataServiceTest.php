<?php

namespace Tests\Unit\Kluis;

use App\Contracts\DailyBarProvider;
use App\Models\User;
use App\Models\VaultSetting;
use App\Services\Kluis\KluisMarketDataService;
use App\Services\Kluis\KluisThermometer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class KluisMarketDataServiceTest extends TestCase
{
    use RefreshDatabase;

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

        $service = new KluisMarketDataService($provider, new KluisThermometer);

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

        $service = new KluisMarketDataService($provider, new KluisThermometer);

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
}
