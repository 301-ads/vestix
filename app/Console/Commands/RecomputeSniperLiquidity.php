<?php

namespace App\Console\Commands;

use App\Services\SniperGroupedDailyIngestService;
use Illuminate\Console\Command;

class RecomputeSniperLiquidity extends Command
{
    protected $signature = 'vestix:sniper-recompute-liquidity {--date= : Metrics as-of date YYYY-MM-DD}';

    protected $description = 'Recompute sniper_liquidity_cache bars_ready / avg volume from existing bars (no API)';

    public function handle(SniperGroupedDailyIngestService $ingest): int
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            $this->warn('Sniper scanner is disabled (VESTIX_SNIPER_SCANNER_ENABLED=false). No-op.');

            return self::SUCCESS;
        }

        $result = $ingest->recomputeLiquidityMetrics($this->option('date') ?: null);
        $this->info("Tickers: {$result['tickers']} | bars_ready: {$result['bars_ready']}");

        return self::SUCCESS;
    }
}
