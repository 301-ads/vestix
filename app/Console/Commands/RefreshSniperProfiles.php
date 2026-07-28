<?php

namespace App\Console\Commands;

use App\Services\SniperProfileRefreshService;
use Illuminate\Console\Command;

class RefreshSniperProfiles extends Command
{
    protected $signature = 'vestix:sniper-refresh-profiles {--limit= : Max Finnhub profile2 calls this run}';

    protected $description = 'Refresh sniper liquidity cache market cap / asset type via Finnhub (budgeted)';

    public function handle(SniperProfileRefreshService $profiles): int
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            $this->warn('Sniper scanner is disabled (VESTIX_SNIPER_SCANNER_ENABLED=false). No-op.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $result = $profiles->refresh($limit);
        $this->info("Refreshed: {$result['refreshed']} | Skipped: {$result['skipped']}");

        return self::SUCCESS;
    }
}
