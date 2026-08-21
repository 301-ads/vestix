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

    public function test_after_market_close_prefers_yahoo_session_mark_over_polygon_bar(): void
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
            'latest_close_price' => 23.52,
            'recent_close_prices' => [23.52],
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
        $quotes->shouldReceive('fetchPremarketPrice')->never();
        $this->app->instance(QuoteProvider::class, $quotes);

        $ok = app(MarketDataFetcher::class)->syncPosition($position, withDelays: false);

        $this->assertTrue($ok);
        $this->assertEqualsWithDelta(24.37, (float) $position->fresh()->latest_close_price, 0.01);
    }

    public function test_lagging_polygon_close_does_not_trigger_false_stopped_out(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 22:00:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'ticker' => 'BEN',
            'status' => 'open',
            'entry_price' => 34.00,
            'quantity' => 45,
            'current_sl' => 33.41,
            'latest_close_price' => 33.97,
            'latest_sma_20' => 33.00,
            'latest_atr_14' => 1.00,
        ]);

        $polygon = Mockery::mock(PolygonMarketDataService::class);
        $polygon->shouldReceive('fetchForTicker')->andReturn([
            'latest_open_price' => 33.00,
            'latest_close_price' => 32.58,
            'recent_close_prices' => [32.58],
            'latest_sma_20' => 33.00,
            'sma_20_five_days_ago' => 32.50,
            'sma_20_ten_days_ago' => 32.00,
            'latest_sma_50' => 31.00,
            'latest_atr_14' => 1.00,
            'scout_rsi' => 48.0,
            'prior_day_low' => 32.00,
        ]);
        $this->app->instance(PolygonMarketDataService::class, $polygon);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchLivePrice')->once()->with('BEN')->andReturn(33.97);
        $this->app->instance(QuoteProvider::class, $quotes);

        $ok = app(MarketDataFetcher::class)->syncPosition($position, withDelays: false);

        $this->assertTrue($ok);
        $fresh = $position->fresh();
        $this->assertEqualsWithDelta(33.97, (float) $fresh->latest_close_price, 0.01);
        $this->assertNotSame('STOPPED OUT', $fresh->action_command);
    }

    public function test_when_live_mark_unavailable_keeps_existing_close_instead_of_lagging_bar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 22:00:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'ticker' => 'EXE',
            'status' => 'open',
            'entry_price' => 95.00,
            'quantity' => 18,
            'current_sl' => 92.58,
            'latest_close_price' => 96.07,
        ]);

        $polygon = Mockery::mock(PolygonMarketDataService::class);
        $polygon->shouldReceive('fetchForTicker')->andReturn([
            'latest_open_price' => 93.00,
            'latest_close_price' => 92.12,
            'recent_close_prices' => [92.12],
            'latest_sma_20' => 93.00,
            'sma_20_five_days_ago' => 92.00,
            'sma_20_ten_days_ago' => 91.00,
            'latest_sma_50' => 90.00,
            'latest_atr_14' => 2.00,
            'scout_rsi' => 55.0,
            'prior_day_low' => 91.00,
        ]);
        $this->app->instance(PolygonMarketDataService::class, $polygon);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchLivePrice')->once()->with('EXE')->andReturn(null);
        $this->app->instance(QuoteProvider::class, $quotes);

        $ok = app(MarketDataFetcher::class)->syncPosition($position, withDelays: false);

        $this->assertTrue($ok);
        $fresh = $position->fresh();
        $this->assertEqualsWithDelta(96.07, (float) $fresh->latest_close_price, 0.01);
        $this->assertNotSame('STOPPED OUT', $fresh->action_command);
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

    public function test_refresh_open_position_live_mark_repairs_stale_close(): void
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

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')->once()->with('PINS', 23.52)->andReturn(24.34);
        $this->app->instance(QuoteProvider::class, $quotes);

        $mark = app(MarketDataFetcher::class)->refreshOpenPositionLiveMark($position, force: true);

        $this->assertEqualsWithDelta(24.34, $mark, 0.01);
        $this->assertEqualsWithDelta(24.34, (float) $position->fresh()->latest_close_price, 0.01);
    }
}
