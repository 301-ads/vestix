<?php

namespace Tests\Unit;

use App\Contracts\QuoteProvider;
use App\Models\Position;
use App\Models\User;
use App\Services\MarketDataFetcher;
use App\Services\PolygonMarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class MarketDataFetcherLiveMarkTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_after_market_close_keeps_session_close_instead_of_stale_live_mark(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 16:30:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'ticker' => 'PINS',
            'status' => 'open',
            'entry_price' => 23.77,
            'quantity' => 59,
            'latest_close_price' => 23.52,
        ]);

        $polygon = Mockery::mock(PolygonMarketDataService::class);
        $polygon->shouldReceive('fetchForTicker')->andReturn([
            'latest_open_price' => 23.80,
            'latest_close_price' => 24.37,
            'recent_close_prices' => [24.37],
            'latest_sma_20' => 23.50,
            'sma_20_five_days_ago' => 23.00,
            'sma_20_ten_days_ago' => 22.50,
            'latest_sma_50' => 22.00,
            'latest_atr_14' => 1.00,
            'scout_rsi' => 58.0,
            'prior_day_low' => 23.00,
        ]);
        $this->app->instance(PolygonMarketDataService::class, $polygon);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchLivePrice')->never();
        $quotes->shouldReceive('fetchPremarketPrice')->never();
        $this->app->instance(QuoteProvider::class, $quotes);

        $ok = app(MarketDataFetcher::class)->syncPosition($position, withDelays: false);

        $this->assertTrue($ok);
        $this->assertEqualsWithDelta(24.37, (float) $position->fresh()->latest_close_price, 0.01);
    }

    public function test_during_rth_live_mark_overrides_session_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'ticker' => 'PINS',
            'status' => 'open',
            'entry_price' => 23.77,
            'quantity' => 59,
            'latest_close_price' => 23.52,
        ]);

        $polygon = Mockery::mock(PolygonMarketDataService::class);
        $polygon->shouldReceive('fetchForTicker')->andReturn([
            'latest_open_price' => 23.80,
            'latest_close_price' => 23.90,
            'recent_close_prices' => [23.90],
            'latest_sma_20' => 23.50,
            'sma_20_five_days_ago' => 23.00,
            'sma_20_ten_days_ago' => 22.50,
            'latest_sma_50' => 22.00,
            'latest_atr_14' => 1.00,
            'scout_rsi' => 58.0,
            'prior_day_low' => 23.00,
        ]);
        $this->app->instance(PolygonMarketDataService::class, $polygon);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchLivePrice')->once()->with('PINS')->andReturn(24.37);
        $this->app->instance(QuoteProvider::class, $quotes);

        $ok = app(MarketDataFetcher::class)->syncPosition($position, withDelays: false);

        $this->assertTrue($ok);
        $this->assertEqualsWithDelta(24.37, (float) $position->fresh()->latest_close_price, 0.01);
    }

    public function test_during_premarket_live_mark_overrides_stale_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-11 05:00:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'ticker' => 'PINS',
            'status' => 'open',
            'entry_price' => 23.79,
            'quantity' => 59,
            'latest_close_price' => 23.52,
        ]);

        $polygon = Mockery::mock(PolygonMarketDataService::class);
        $polygon->shouldReceive('fetchForTicker')->andReturn([
            'latest_open_price' => 23.80,
            'latest_close_price' => 24.37,
            'recent_close_prices' => [23.68, 24.37],
            'latest_sma_20' => 23.50,
            'sma_20_five_days_ago' => 23.00,
            'sma_20_ten_days_ago' => 22.50,
            'latest_sma_50' => 22.00,
            'latest_atr_14' => 1.00,
            'scout_rsi' => 58.0,
            'prior_day_low' => 23.00,
        ]);
        $this->app->instance(PolygonMarketDataService::class, $polygon);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')->once()->with('PINS', 24.37)->andReturn(24.34);
        $this->app->instance(QuoteProvider::class, $quotes);

        $ok = app(MarketDataFetcher::class)->syncPosition($position, withDelays: false);

        $this->assertTrue($ok);
        $this->assertEqualsWithDelta(24.34, (float) $position->fresh()->latest_close_price, 0.01);
    }
}
