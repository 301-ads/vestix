<?php

namespace Tests\Feature\Console;

use App\Enums\KluisClimate;
use App\Models\User;
use App\Models\VaultSetting;
use App\Services\Kluis\KluisMarketDataService;
use App\Support\Kluis\KluisThermometerReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RefreshKluisThermometerTest extends TestCase
{
    use RefreshDatabase;

    public function test_refreshes_unique_tickers_once(): void
    {
        Cache::flush();

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        VaultSetting::defaultsFor($userA)->fill(['etf_ticker' => 'VWCE'])->save();
        VaultSetting::defaultsFor($userB)->fill(['etf_ticker' => 'VWCE'])->save();

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 2,
            close: 165,
            sma200: 160,
            ticker: 'VWCE',
        );

        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchReading')
            ->once()
            ->with(Mockery::on(fn ($s) => strtoupper((string) $s->etf_ticker) === 'VWCE'), true)
            ->andReturn($reading);
        $market->shouldReceive('fetchHoldingsPrice')
            ->once()
            ->with('VWCE', true)
            ->andReturn(['price' => 165.20, 'resolved_symbol' => 'VWCE.DE']);
        $this->app->instance(KluisMarketDataService::class, $market);

        $this->artisan('vestix:kluis-refresh-thermometer')
            ->assertSuccessful();
    }

    public function test_succeeds_when_no_settings(): void
    {
        $this->artisan('vestix:kluis-refresh-thermometer')
            ->assertSuccessful();
    }
}
