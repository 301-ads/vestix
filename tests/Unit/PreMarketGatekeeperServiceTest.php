<?php

namespace Tests\Unit;

use App\Contracts\QuoteProvider;
use App\Enums\PremarketGapStatus;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\PreMarketGatekeeperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PreMarketGatekeeperServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_detects_gap_up_when_premarket_above_entry_trigger(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'ON',
            'entry_price' => 50.00,
            'signal_high' => 49.00,
            'latest_atr_14' => 10.00,
            'armed_for_entry_on' => Carbon::now('America/New_York')->toDateString(),
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('ON')
            ->andReturn(55.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketGapStatus::GapUp, $status);

        $position->refresh();
        $this->assertSame(PremarketGapStatus::GapUp, $position->premarket_gap_status);
        $this->assertSame('55.00', $position->premarket_price);
        $this->assertSame('50.00', $position->premarket_entry_trigger);
        $this->assertGreaterThan(0, (float) $position->premarket_gap_pct);
        $this->assertNotNull($position->premarket_checked_at);
        $this->assertEquals(1, PositionAlert::query()->count());
    }

    public function test_marks_ok_when_premarket_at_or_below_entry_trigger(): void
    {
        $position = Position::factory()->scout()->create([
            'entry_price' => 50.00,
            'signal_high' => 49.00,
            'latest_atr_14' => 10.00,
            'armed_for_entry_on' => Carbon::now('America/New_York')->toDateString(),
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($position->ticker)
            ->andReturn(50.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketGapStatus::Ok, $status);
        $this->assertEquals(0, PositionAlert::query()->count());
    }

    public function test_marks_unavailable_when_quote_missing(): void
    {
        $position = Position::factory()->scout()->create([
            'entry_price' => 50.00,
            'signal_high' => 49.00,
            'armed_for_entry_on' => Carbon::now('America/New_York')->toDateString(),
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->andReturn(null);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketGapStatus::Unavailable, $status);
        $position->refresh();
        $this->assertNull($position->premarket_price);
    }

    public function test_run_only_checks_armed_scouts_with_entry_price(): void
    {
        $tradingDay = Carbon::parse('2026-06-15', 'America/New_York');
        Carbon::setTestNow($tradingDay->copy()->setTimezone('Europe/Amsterdam')->setTime(15, 0));

        $armed = Position::factory()->scout()->create([
            'entry_price' => 50.00,
            'signal_high' => 49.00,
            'armed_for_entry_on' => $tradingDay->toDateString(),
        ]);

        Position::factory()->scout()->create([
            'entry_price' => 50.00,
            'signal_high' => 49.00,
            'armed_for_entry_on' => null,
        ]);

        Position::factory()->scout()->create([
            'entry_price' => null,
            'armed_for_entry_on' => $tradingDay->toDateString(),
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($armed->ticker)
            ->andReturn(48.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $summary = app(PreMarketGatekeeperService::class)->run($tradingDay);

        $this->assertSame(1, $summary['checked']);
        $this->assertSame(1, $summary['gap_down']);
    }
}
