<?php

namespace Tests\Unit;

use App\Models\SniperDailyBar;
use App\Models\SniperLiquidityCache;
use App\Services\PolygonDailyBarService;
use App\Services\PolygonGroupedDailyService;
use App\Services\SniperGroupedDailyIngestService;
use App\Services\SniperSplitGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SniperSplitGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_small_gap_is_ok(): void
    {
        $grouped = Mockery::mock(PolygonGroupedDailyService::class);
        $grouped->shouldNotReceive('fetchSplits');
        $bars = Mockery::mock(PolygonDailyBarService::class);

        $guard = new SniperSplitGuard($grouped, $bars);

        $this->assertSame('ok', $guard->decide('NVDA', '2026-07-28', 100.0, 95.0));
    }

    public function test_large_gap_without_split_api_skips_instead_of_purge(): void
    {
        $grouped = Mockery::mock(PolygonGroupedDailyService::class);
        $grouped->shouldReceive('fetchSplits')->once()->andReturn([]);
        $bars = Mockery::mock(PolygonDailyBarService::class);

        $guard = new SniperSplitGuard($grouped, $bars);

        $this->assertSame('skip', $guard->decide('NVDA', '2026-07-28', 100.0, 1000.0));
    }

    public function test_confirmed_split_purges_and_backfills(): void
    {
        SniperDailyBar::query()->create([
            'ticker' => 'NVDA',
            'date' => '2026-07-25',
            'open' => 1000,
            'high' => 1010,
            'low' => 990,
            'close' => 1000,
            'volume' => 1_000_000,
        ]);

        $grouped = Mockery::mock(PolygonGroupedDailyService::class);
        $grouped->shouldReceive('fetchSplits')->once()->andReturn([
            ['execution_date' => '2026-07-28', 'split_from' => 10, 'split_to' => 1],
        ]);

        $bars = Mockery::mock(PolygonDailyBarService::class);
        $bars->shouldReceive('fetchRecentBars')->once()->andReturn([
            'today' => ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1.0],
            'adv30' => 1_000_000.0,
            'bars' => array_map(function (int $i): array {
                return [
                    'open' => 100.0,
                    'high' => 101.0,
                    'low' => 99.0,
                    'close' => 100.0 + ($i * 0.01),
                    'volume' => 1_000_000.0,
                    'date' => now('America/New_York')->subDays(60 - $i)->toDateString(),
                ];
            }, range(0, 54)),
        ]);

        $guard = new SniperSplitGuard($grouped, $bars);
        $this->assertSame('purge', $guard->decide('NVDA', '2026-07-28', 100.0, 1000.0));
        $guard->purgeAndBackfill('NVDA');

        $this->assertGreaterThan(50, SniperDailyBar::query()->where('ticker', 'NVDA')->count());
        $this->assertDatabaseHas('sniper_liquidity_cache', [
            'ticker' => 'NVDA',
            'bars_ready' => 1,
        ]);
    }

    public function test_recompute_marks_bars_ready_with_sufficient_history(): void
    {
        config(['vestix.sniper_scanner.min_bars_for_ready' => 50]);

        $date = now('America/New_York')->subDays(90)->startOfDay();

        for ($i = 0; $i < 55; $i++) {
            while (! $date->isWeekday()) {
                $date->addDay();
            }

            SniperDailyBar::query()->create([
                'ticker' => 'AAAA',
                'date' => $date->toDateString(),
                'open' => 100,
                'high' => 101,
                'low' => 99,
                'close' => 100,
                'volume' => 2_000_000,
            ]);
            $date->addDay();
        }

        $latest = SniperDailyBar::query()->where('ticker', 'AAAA')->orderByDesc('date')->value('date');
        $asOf = is_string($latest) ? $latest : $latest?->toDateString();

        $ingest = app(SniperGroupedDailyIngestService::class);
        $result = $ingest->recomputeLiquidityMetrics($asOf);

        $this->assertGreaterThan(0, $result['bars_ready']);
        $this->assertTrue((bool) SniperLiquidityCache::query()->where('ticker', 'AAAA')->value('bars_ready'));
    }
}
