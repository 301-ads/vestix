<?php

namespace App\Console\Commands;

use App\Models\SniperDailyBar;
use App\Models\SniperLiquidityCache;
use Illuminate\Console\Command;

class DiagnoseSniperCache extends Command
{
    protected $signature = 'vestix:sniper-diagnose {--ticker=SPY : Sample ticker to inspect}';

    protected $description = 'Diagnose sniper bar cache / liquidity readiness';

    public function handle(): int
    {
        $ticker = strtoupper((string) $this->option('ticker'));

        $this->table(['Check', 'Value'], [
            ['enabled', config('vestix.sniper_scanner.enabled') ? 'true' : 'false'],
            ['owner_user_id', (string) config('vestix.sniper_scanner.owner_user_id')],
            ['min_bars_for_ready', (string) config('vestix.sniper_scanner.min_bars_for_ready')],
            ['distinct_dates', (string) SniperDailyBar::query()->distinct()->count('date')],
            ['bar_rows', (string) SniperDailyBar::query()->count()],
            ['cache_rows', (string) SniperLiquidityCache::query()->count()],
            ['bars_ready', (string) SniperLiquidityCache::query()->where('bars_ready', true)->count()],
            ['with_market_cap', (string) SniperLiquidityCache::query()->whereNotNull('market_cap')->count()],
            ["{$ticker}_bar_count", (string) SniperDailyBar::query()->where('ticker', $ticker)->count()],
            ["{$ticker}_bars_ready", (string) (SniperLiquidityCache::query()->where('ticker', $ticker)->value('bars_ready') ? 'true' : 'false')],
            ["{$ticker}_last_volume", (string) (SniperLiquidityCache::query()->where('ticker', $ticker)->value('last_volume') ?? 'null')],
            ["{$ticker}_avg_volume_30d", (string) (SniperLiquidityCache::query()->where('ticker', $ticker)->value('avg_volume_30d') ?? 'null')],
            ["{$ticker}_market_cap", (string) (SniperLiquidityCache::query()->where('ticker', $ticker)->value('market_cap') ?? 'null')],
            ["{$ticker}_asset_type", (string) (SniperLiquidityCache::query()->where('ticker', $ticker)->value('asset_type') ?? 'null')],
            ['recompute_command', class_exists(\App\Console\Commands\RecomputeSniperLiquidity::class) ? 'present' : 'MISSING — redeploy'],
        ]);

        return self::SUCCESS;
    }
}
