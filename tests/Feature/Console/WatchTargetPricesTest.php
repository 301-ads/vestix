<?php

namespace Tests\Feature\Console;

use App\Contracts\QuoteProvider;
use App\Enums\Broker;
use App\Jobs\CheckTarget1AlertsJob;
use App\Models\Position;
use App\Models\User;
use App\Support\UsMarketSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            ->expectsOutput('Buiten intraday Target 1-venster — overgeslagen.')
            ->assertSuccessful();
    }

    public function test_command_updates_only_latest_close_price_for_revolut_positions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'America/New_York'));
        Queue::fake();

        $revolutUser = User::factory()->create(['primary_broker' => Broker::Revolut]);
        $otherUser = User::factory()->create(['primary_broker' => Broker::None]);

        $position = Position::factory()->for($revolutUser)->create([
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

        Position::factory()->for($otherUser)->create([
            'ticker' => 'MSFT',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('AAPL')
            ->andReturn(11.25);
        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $this->artisan('vestix:watch-target-prices', ['--force' => true])
            ->expectsOutput('AAPL: $11.25')
            ->assertSuccessful();

        $position->refresh();

        $this->assertEquals(11.25, (float) $position->latest_close_price);
        $this->assertEquals(9.50, (float) $position->latest_sma_20);
        $this->assertEquals(55.00, (float) $position->scout_rsi);

        Queue::assertPushed(CheckTarget1AlertsJob::class);
    }

    public function test_command_skips_auto_runner_bypass_positions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'America/New_York'));
        Queue::fake();

        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'quantity' => 22,
            'status' => 'open',
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldNotReceive('fetchLivePrice');
        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $this->artisan('vestix:watch-target-prices', ['--force' => true])
            ->expectsOutput('Geen open Revolut-posities om te monitoren.')
            ->assertSuccessful();
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
