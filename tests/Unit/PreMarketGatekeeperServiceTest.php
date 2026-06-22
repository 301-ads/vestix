<?php

namespace Tests\Unit;

use App\Contracts\QuoteProvider;
use App\Enums\AlertEventType;
use App\Enums\PremarketScanResult;
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

    public function test_detects_gap_risk_when_premarket_more_than_one_percent_above_bounce_high(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'KDP',
            'signal_high' => 49.00,
            'entry_price' => 50.00,
            'latest_atr_14' => 10.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('KDP')
            ->andReturn(50.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketScanResult::GapRisk, $status);

        $position->refresh();
        $this->assertSame(PremarketScanResult::GapRisk, $position->premarket_scan_type);
        $this->assertSame('50.00', $position->premarket_price);
        $this->assertSame('49.00', $position->premarket_reference_price);
        $this->assertGreaterThan(1.0, (float) $position->premarket_distance_pct);
        $this->assertNotNull($position->premarket_checked_at);
        $this->assertEquals(1, PositionAlert::query()->count());
    }

    public function test_marks_ok_when_premarket_within_gap_threshold_above_bounce_high(): void
    {
        $position = Position::factory()->scout()->create([
            'signal_high' => 49.00,
            'entry_price' => 50.00,
            'latest_atr_14' => 10.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($position->ticker)
            ->andReturn(49.40);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketScanResult::Ok, $status);
        $this->assertEquals(0, PositionAlert::query()->count());
    }

    public function test_marks_unavailable_when_quote_missing(): void
    {
        $position = Position::factory()->scout()->create([
            'signal_high' => 49.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->andReturn(null);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketScanResult::Unavailable, $status);
        $position->refresh();
        $this->assertNull($position->premarket_price);
    }

    public function test_detects_reclamation_when_premarket_crosses_above_sma_20(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'SJM',
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 98.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('SJM')
            ->andReturn(101.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketScanResult::Reclamation, $status);
        $position->refresh();
        $this->assertSame(PremarketScanResult::Reclamation, $position->premarket_scan_type);
        $this->assertSame('100.00', $position->premarket_reference_price);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::PremarketReclamation->value)->count());
    }

    public function test_detects_landing_when_premarket_within_distance_below_sma_20(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'LYV',
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 97.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with('LYV')
            ->andReturn(99.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertSame(PremarketScanResult::Landing, $status);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::PremarketLanding->value)->count());
    }

    public function test_skips_opportunity_track_when_close_already_above_sma_20(): void
    {
        $position = Position::factory()->scout()->create([
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 101.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldNotReceive('fetchLivePrice');

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $status = app(PreMarketGatekeeperService::class)->checkPosition($position->fresh());

        $this->assertNull($status);
    }

    public function test_scans_track_a_before_track_b(): void
    {
        Position::factory()->scout()->create([
            'ticker' => 'AAA',
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 98.00,
        ]);

        $trackA = Position::factory()->scout()->create([
            'ticker' => 'ZZZ',
            'signal_high' => 49.00,
            'entry_price' => 50.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->ordered()
            ->with('ZZZ')
            ->andReturn(48.00);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->ordered()
            ->with('AAA')
            ->andReturn(99.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        app(PreMarketGatekeeperService::class)->run();
    }

    public function test_prioritized_scouts_sort_execution_ready_before_opportunity(): void
    {
        $executionReady = Position::factory()->scout()->create([
            'ticker' => 'ZZZ',
            'signal_high' => 49.00,
            'entry_price' => 50.00,
        ]);

        $bounceOnly = Position::factory()->scout()->create([
            'ticker' => 'MMM',
            'signal_high' => 40.00,
            'entry_price' => null,
        ]);

        $opportunity = Position::factory()->scout()->create([
            'ticker' => 'AAA',
            'signal_high' => null,
        ]);

        $ordered = app(PreMarketGatekeeperService::class)
            ->prioritizedScouts()
            ->pluck('ticker')
            ->all();

        $this->assertSame(['ZZZ', 'MMM', 'AAA'], $ordered);
    }

    public function test_estimate_api_calls_counts_track_a_and_eligible_track_b(): void
    {
        Position::factory()->scout()->create([
            'signal_high' => 49.00,
        ]);

        Position::factory()->scout()->create([
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 98.00,
        ]);

        Position::factory()->scout()->create([
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 101.00,
        ]);

        $this->assertSame(2, app(PreMarketGatekeeperService::class)->estimateApiCalls());
    }

    public function test_run_scans_all_watchlist_scouts(): void
    {
        $tradingDay = Carbon::parse('2026-06-15', 'America/New_York');
        Carbon::setTestNow($tradingDay->copy()->setTimezone('Europe/Amsterdam')->setTime(15, 0));

        $withBounce = Position::factory()->scout()->create([
            'signal_high' => 49.00,
            'entry_price' => 50.00,
        ]);

        $withoutBounce = Position::factory()->scout()->create([
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 98.00,
        ]);

        Position::factory()->scout()->create([
            'signal_high' => null,
            'latest_sma_20' => 100.00,
            'latest_close_price' => 101.00,
        ]);

        $quoteProvider = Mockery::mock(QuoteProvider::class);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($withBounce->ticker)
            ->andReturn(48.00);
        $quoteProvider->shouldReceive('fetchLivePrice')
            ->once()
            ->with($withoutBounce->ticker)
            ->andReturn(99.00);

        $this->app->instance(QuoteProvider::class, $quoteProvider);

        $summary = app(PreMarketGatekeeperService::class)->run($tradingDay);

        $this->assertSame(2, $summary['checked']);
        $this->assertSame(1, $summary['ok']);
        $this->assertSame(1, $summary['landing']);
        $this->assertSame(1, $summary['skipped']);
    }
}
