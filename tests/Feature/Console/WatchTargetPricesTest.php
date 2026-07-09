<?php

namespace Tests\Feature\Console;

use App\Contracts\QuoteProvider;
use App\Enums\Broker;
use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\CheckTarget1AlertsJob;
use App\Models\Position;
use App\Models\User;
use App\Support\MarketDataFreshness;
use App\Support\UsMarketSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WatchTargetPricesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_skips_outside_intraday_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'America/New_York'));

        $this->artisan('vestix:watch-target-prices')
            ->expectsOutput('Buiten intraday-venster — overgeslagen.')
            ->assertSuccessful();
    }

    public function test_command_updates_latest_close_price_for_all_open_positions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'America/New_York'));
        Queue::fake();
        Cache::flush();

        $revolutUser = User::factory()->create(['primary_broker' => Broker::Revolut]);
        $otherUser = User::factory()->create(['primary_broker' => Broker::None]);

        $revolutPosition = Position::factory()->for($revolutUser)->create([
            'ticker' => 'AAPL',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.00,
            'latest_sma_20' => 9.50,
            'latest_atr_14' => 1.00,
            'scout_rsi' => 55.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $manualPosition = Position::factory()->for($otherUser)->create([
            'ticker' => 'MSFT',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.00,
            'latest_sma_20' => 9.50,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('AAPL')
            ->andReturn(11.25);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('MSFT')
            ->andReturn(12.50);
        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $this->artisan('vestix:watch-target-prices', ['--force' => true])
            ->expectsOutput('AAPL: $11.25')
            ->expectsOutput('MSFT: $12.50')
            ->assertSuccessful();

        $revolutPosition->refresh();
        $manualPosition->refresh();

        $this->assertEquals(11.25, (float) $revolutPosition->latest_close_price);
        $this->assertEquals(12.50, (float) $manualPosition->latest_close_price);
        $this->assertEquals(9.50, (float) $revolutPosition->latest_sma_20);
        $this->assertEquals(55.00, (float) $revolutPosition->scout_rsi);
        $this->assertNotNull(MarketDataFreshness::lastIntradayQuoteAt());

        Queue::assertPushed(CheckTarget1AlertsJob::class);
        Queue::assertPushed(CheckPositionAlertTriggersJob::class);
    }

    public function test_command_updates_auto_runner_bypass_positions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'America/New_York'));
        Queue::fake();

        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        $position = Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'quantity' => 22,
            'status' => 'open',
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('BAC')
            ->andReturn(60.10);
        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $this->artisan('vestix:watch-target-prices', ['--force' => true])
            ->expectsOutput('BAC: $60.10')
            ->assertSuccessful();

        $this->assertEquals(60.10, (float) $position->fresh()->latest_close_price);
    }

    public function test_intraday_window_covers_premarket_and_regular_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 05:00:00', 'America/New_York'));
        $this->assertTrue(UsMarketSession::isIntradayTargetWatchWindow());

        Carbon::setTestNow(Carbon::parse('2026-07-09 12:00:00', 'America/New_York'));
        $this->assertTrue(UsMarketSession::isIntradayTargetWatchWindow());

        Carbon::setTestNow(Carbon::parse('2026-07-09 16:15:00', 'America/New_York'));
        $this->assertTrue(UsMarketSession::isIntradayTargetWatchWindow());

        Carbon::setTestNow(Carbon::parse('2026-07-09 16:16:00', 'America/New_York'));
        $this->assertFalse(UsMarketSession::isIntradayTargetWatchWindow());
    }
}
