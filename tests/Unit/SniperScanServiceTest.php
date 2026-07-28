<?php

namespace Tests\Unit;

use App\Enums\TradeDirection;
use App\Models\Asset;
use App\Models\Position;
use App\Models\SniperLiquidityCache;
use App\Models\User;
use App\Services\AssetSyncService;
use App\Services\EarningsCalendarSyncService;
use App\Services\SniperGroupedDailyIngestService;
use App\Services\SniperScanService;
use App\Support\SniperLocalIndicators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SniperScanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_scanner_is_noop(): void
    {
        config(['vestix.sniper_scanner.enabled' => false]);

        $service = app(SniperScanService::class);
        $result = $service->run(dryRun: true);

        $this->assertFalse($result['enabled']);
        $this->assertSame('disabled', $result['reason']);
    }

    public function test_creates_scout_for_math_hit_when_enabled(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.min_volume' => 1_000_000,
            'vestix.sniper_scanner.min_avg_volume_30d' => 1_000_000,
            'vestix.sniper_scanner.min_market_cap' => 2_000_000_000,
            'vestix.finnhub.rate_limit_delay' => 0,
        ]);

        SniperLiquidityCache::query()->create([
            'ticker' => 'AAAA',
            'asset_type' => 'CS',
            'avg_volume_30d' => 2_000_000,
            'last_volume' => 2_000_000,
            'market_cap' => 5_000_000_000,
            'enabled' => true,
            'bars_ready' => true,
        ]);

        $indicators = Mockery::mock(SniperLocalIndicators::class);
        $indicators->shouldReceive('forTicker')->with('AAAA')->andReturn([
            'open' => 100.0,
            'high' => 101.2,
            'low' => 99.8,
            'close' => 101.0,
            'volume' => 2_000_000,
            'date' => '2026-07-27',
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 48.0,
        ]);

        $ingest = Mockery::mock(SniperGroupedDailyIngestService::class);
        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'AAAA',
                'next_earnings_date' => now()->addDays(30)->toDateString(),
            ])
        );

        $service = new SniperScanService($ingest, $earnings, $assets, $indicators);
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('positions', [
            'user_id' => $user->id,
            'ticker' => 'AAAA',
            'status' => 'scout',
            'source' => 'sniper_scan',
            'review_status' => 'pending_visual_review',
            'direction' => TradeDirection::Long->value,
        ]);
    }

    public function test_earnings_cap_skips_overflow_without_creating(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.max_earnings_checks_per_run' => 1,
            'vestix.finnhub.rate_limit_delay' => 0,
        ]);

        foreach (['AAAA', 'BBBB'] as $ticker) {
            SniperLiquidityCache::query()->create([
                'ticker' => $ticker,
                'asset_type' => 'CS',
                'avg_volume_30d' => 2_000_000,
                'last_volume' => 2_000_000,
                'market_cap' => 5_000_000_000,
                'enabled' => true,
                'bars_ready' => true,
            ]);
        }

        $indicators = Mockery::mock(SniperLocalIndicators::class);
        $indicators->shouldReceive('forTicker')->andReturn([
            'open' => 100.0,
            'high' => 101.2,
            'low' => 99.8,
            'close' => 101.0,
            'volume' => 2_000_000,
            'date' => '2026-07-27',
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 48.0,
        ]);

        $ingest = Mockery::mock(SniperGroupedDailyIngestService::class);
        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturnUsing(function (string $ticker) {
            return Asset::query()->create([
                'ticker' => $ticker,
                'next_earnings_date' => now()->addDays(40)->toDateString(),
            ]);
        });

        $service = new SniperScanService($ingest, $earnings, $assets, $indicators);
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(2, $result['math_hits']);
        $this->assertSame(1, $result['earnings_capped']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, Position::query()->scout()->count());
    }

    public function test_blocks_earnings_inside_cutoff(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.earnings_cutoff_days' => 14,
            'vestix.finnhub.rate_limit_delay' => 0,
        ]);

        SniperLiquidityCache::query()->create([
            'ticker' => 'EARN',
            'asset_type' => 'CS',
            'avg_volume_30d' => 2_000_000,
            'last_volume' => 2_000_000,
            'market_cap' => 5_000_000_000,
            'enabled' => true,
            'bars_ready' => true,
        ]);

        $indicators = Mockery::mock(SniperLocalIndicators::class);
        $indicators->shouldReceive('forTicker')->andReturn([
            'open' => 100.0,
            'high' => 101.2,
            'low' => 99.8,
            'close' => 101.0,
            'volume' => 2_000_000,
            'date' => '2026-07-27',
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 48.0,
        ]);

        $ingest = Mockery::mock(SniperGroupedDailyIngestService::class);
        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'EARN',
                'next_earnings_date' => now('Europe/Amsterdam')->addDays(5)->toDateString(),
            ])
        );

        $service = new SniperScanService($ingest, $earnings, $assets, $indicators);
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(1, $result['earnings_blocked']);
        $this->assertSame(0, $result['created']);
    }
}
