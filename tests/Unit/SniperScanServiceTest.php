<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Enums\TradeDirection;
use App\Models\Asset;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\SniperLiquidityCache;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\AssetSyncService;
use App\Services\EarningsCalendarSyncService;
use App\Services\MarketDataFetcher;
use App\Services\SniperGroupedDailyIngestService;
use App\Services\SniperScanService;
use App\Support\SniperLocalIndicators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
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
            'vestix.polygon.rate_limit_delay' => 0,
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

        $indicators = $this->mockIndicators('AAAA', $this->longHitIndicators());
        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);
        $marketData = Mockery::mock(MarketDataFetcher::class);
        $alerts = Mockery::mock(AlertDispatcher::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'AAAA',
                'next_earnings_date' => now()->addDays(30)->toDateString(),
            ])
        );
        $marketData->shouldReceive('syncPosition')->once()->andReturnUsing(function (Position $position): bool {
            $position->update([
                'sector_etf' => 'XLK',
                'sector_trend_positive' => true,
                'relative_volume' => 1.4,
                'sma_20_five_days_ago' => 99.0,
                'sma_20_ten_days_ago' => 98.0,
                'pre_bounce_extension_atr' => 2.5,
            ]);

            return true;
        });
        $alerts->shouldReceive('dispatchNow')->once()->andReturn(true);

        $service = $this->makeService($indicators, $earnings, $assets, $marketData, $alerts);
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['enriched']);
        $this->assertSame(1, $result['notified']);
        $this->assertDatabaseHas('positions', [
            'user_id' => $user->id,
            'ticker' => 'AAAA',
            'status' => 'scout',
            'source' => 'sniper_scan',
            'review_status' => 'pending_visual_review',
            'direction' => TradeDirection::Long->value,
        ]);

        $position = Position::query()->where('ticker', 'AAAA')->first();
        $this->assertNotNull($position);
        $this->assertNotNull($position->last_setup_score);
    }

    public function test_enrich_and_telegram_alert_include_setup_score(): void
    {
        config([
            'vestix.telegram.bot_token' => 'test-token',
            'vestix.sniper_scanner.enabled' => true,
            'vestix.finnhub.rate_limit_delay' => 0,
            'vestix.polygon.rate_limit_delay' => 0,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create([
            'is_short_enabled' => true,
            'telegram_chat_id' => '12345',
        ]);
        UserAlertPreference::ensureDefaultsForUser($user);

        config(['vestix.sniper_scanner.owner_user_id' => $user->id]);

        SniperLiquidityCache::query()->create([
            'ticker' => 'NEM',
            'asset_type' => 'CS',
            'avg_volume_30d' => 2_000_000,
            'last_volume' => 2_000_000,
            'market_cap' => 5_000_000_000,
            'enabled' => true,
            'bars_ready' => true,
        ]);

        $indicators = $this->mockIndicators('NEM', [
            'open' => 96.0,
            'high' => 98.0,
            'low' => 94.5,
            'close' => 94.88,
            'volume' => 2_000_000,
            'date' => '2026-07-27',
            'sma10' => 94.0,
            'sma20' => 95.5,
            'sma50' => 97.0,
            'rsi14' => 48.0,
        ]);

        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);
        $marketData = Mockery::mock(MarketDataFetcher::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'NEM',
                'next_earnings_date' => now()->addDays(40)->toDateString(),
            ])
        );
        $marketData->shouldReceive('syncPosition')->once()->andReturnUsing(function (Position $position): bool {
            $position->update([
                'signal_high' => 98.0,
                'latest_open_price' => 96.0,
                'latest_close_price' => 94.88,
                'latest_sma_20' => 95.5,
                'latest_sma_50' => 97.0,
                'sma_20_five_days_ago' => 96.0,
                'sma_20_ten_days_ago' => 96.8,
                'scout_rsi' => 48.0,
                'relative_volume' => 1.2,
                'sector_etf' => 'XLB',
                'sector_trend_positive' => false,
                'pre_bounce_extension_atr' => 2.2,
            ]);

            return true;
        });

        $service = $this->makeService(
            $indicators,
            $earnings,
            $assets,
            $marketData,
            app(AlertDispatcher::class),
        );
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['enriched']);
        $this->assertSame(1, $result['notified']);

        $position = Position::query()->where('ticker', 'NEM')->first();
        $this->assertNotNull($position);
        $this->assertSame(TradeDirection::Short, $position->tradeDirection());
        $this->assertNotNull($position->last_setup_score);
        $this->assertGreaterThanOrEqual(5, (int) $position->last_setup_score);

        $this->assertEquals(1, PositionAlert::query()
            ->where('event_type', AlertEventType::SniperScanTarget)
            ->count());

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) ($body['text'] ?? ''), 'NEM')
                && str_contains((string) ($body['text'] ?? ''), 'Score:');
        });
    }

    public function test_earnings_cap_skips_overflow_without_creating(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.max_earnings_checks_per_run' => 1,
            'vestix.finnhub.rate_limit_delay' => 0,
            'vestix.polygon.rate_limit_delay' => 0,
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
        $indicators->shouldReceive('forTicker')->andReturn($this->longHitIndicators());

        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);
        $marketData = Mockery::mock(MarketDataFetcher::class);
        $alerts = Mockery::mock(AlertDispatcher::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturnUsing(function (string $ticker) {
            return Asset::query()->create([
                'ticker' => $ticker,
                'next_earnings_date' => now()->addDays(40)->toDateString(),
            ]);
        });
        $marketData->shouldReceive('syncPosition')->once()->andReturn(true);
        $alerts->shouldReceive('dispatchNow')->once()->andReturn(true);

        $service = $this->makeService($indicators, $earnings, $assets, $marketData, $alerts);
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(2, $result['math_hits']);
        $this->assertSame(1, $result['earnings_capped']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, Position::query()->scout()->count());
    }

    public function test_allowlist_etf_without_market_cap_can_be_liquid(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.etf_allowlist' => ['SPY'],
            'vestix.finnhub.rate_limit_delay' => 0,
            'vestix.polygon.rate_limit_delay' => 0,
        ]);

        SniperLiquidityCache::query()->create([
            'ticker' => 'SPY',
            'asset_type' => 'ETF',
            'avg_volume_30d' => 50_000_000,
            'last_volume' => 40_000_000,
            'market_cap' => null,
            'enabled' => true,
            'bars_ready' => true,
        ]);

        $indicators = $this->mockIndicators('SPY', [
            ...$this->longHitIndicators(),
            'volume' => 40_000_000,
        ]);

        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'SPY',
                'next_earnings_date' => now()->addDays(40)->toDateString(),
            ])
        );

        $service = $this->makeService(
            $indicators,
            $earnings,
            $assets,
            Mockery::mock(MarketDataFetcher::class),
            Mockery::mock(AlertDispatcher::class),
        );
        $result = $service->run(dryRun: true, skipIngest: true);

        $this->assertSame(1, $result['liquid']);
        $this->assertSame(1, $result['math_hits']);
    }

    public function test_blocks_earnings_inside_cutoff(): void
    {
        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.earnings_cutoff_days' => 14,
            'vestix.finnhub.rate_limit_delay' => 0,
            'vestix.polygon.rate_limit_delay' => 0,
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
        $indicators->shouldReceive('forTicker')->andReturn($this->longHitIndicators());

        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'EARN',
                'next_earnings_date' => now('Europe/Amsterdam')->addDays(5)->toDateString(),
            ])
        );

        $service = $this->makeService(
            $indicators,
            $earnings,
            $assets,
            Mockery::mock(MarketDataFetcher::class),
            Mockery::mock(AlertDispatcher::class),
        );
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(1, $result['earnings_blocked']);
        $this->assertSame(0, $result['created']);
    }

    public function test_blocks_post_earnings_quarantine_even_when_next_is_far(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05', 'Europe/Amsterdam'));

        $user = User::factory()->create(['is_short_enabled' => true]);

        config([
            'vestix.sniper_scanner.enabled' => true,
            'vestix.sniper_scanner.owner_user_id' => $user->id,
            'vestix.sniper_scanner.earnings_cutoff_days' => 14,
            'vestix.earnings_quarantine.trading_days' => 2,
            'vestix.finnhub.rate_limit_delay' => 0,
            'vestix.polygon.rate_limit_delay' => 0,
        ]);

        SniperLiquidityCache::query()->create([
            'ticker' => 'EC',
            'asset_type' => 'CS',
            'avg_volume_30d' => 2_000_000,
            'last_volume' => 2_000_000,
            'market_cap' => 5_000_000_000,
            'enabled' => true,
            'bars_ready' => true,
        ]);

        $indicators = Mockery::mock(SniperLocalIndicators::class);
        $indicators->shouldReceive('forTicker')->andReturn($this->longHitIndicators());

        $earnings = Mockery::mock(EarningsCalendarSyncService::class);
        $assets = Mockery::mock(AssetSyncService::class);

        $earnings->shouldReceive('syncTicker')->once()->andReturn('synced');
        $assets->shouldReceive('ensureForTicker')->once()->andReturn(
            Asset::query()->create([
                'ticker' => 'EC',
                'last_earnings_date' => '2026-08-04',
                'next_earnings_date' => '2026-11-04',
            ])
        );

        $service = $this->makeService(
            $indicators,
            $earnings,
            $assets,
            Mockery::mock(MarketDataFetcher::class),
            Mockery::mock(AlertDispatcher::class),
        );
        $result = $service->run(dryRun: false, skipIngest: true);

        $this->assertSame(1, $result['earnings_blocked']);
        $this->assertSame(0, $result['created']);
    }

    /**
     * @return array{
     *     open: float,
     *     high: float,
     *     low: float,
     *     close: float,
     *     volume: int,
     *     date: string,
     *     sma10: float,
     *     sma20: float,
     *     sma50: float,
     *     rsi14: float,
     * }
     */
    private function longHitIndicators(): array
    {
        return [
            'open' => 100.0,
            'high' => 101.2,
            'low' => 99.8,
            'close' => 101.0,
            'volume' => 2_000_000,
            'date' => '2026-07-27',
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma20FiveDaysAgo' => 99.0,
            'sma50' => 98.0,
            'rsi14' => 48.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mockIndicators(string $ticker, array $payload): MockInterface
    {
        $indicators = Mockery::mock(SniperLocalIndicators::class);
        $indicators->shouldReceive('forTicker')->with($ticker)->andReturn($payload);

        return $indicators;
    }

    private function makeService(
        SniperLocalIndicators $indicators,
        EarningsCalendarSyncService $earnings,
        AssetSyncService $assets,
        MarketDataFetcher $marketData,
        AlertDispatcher $alerts,
    ): SniperScanService {
        return new SniperScanService(
            Mockery::mock(SniperGroupedDailyIngestService::class),
            $earnings,
            $assets,
            $indicators,
            $marketData,
            $alerts,
        );
    }
}
