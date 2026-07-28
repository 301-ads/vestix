<?php

namespace App\Console\Commands;

use App\Services\SniperGroupedDailyIngestService;
use Illuminate\Console\Command;

class IngestSniperGroupedDaily extends Command
{
    protected $signature = 'vestix:sniper-ingest-grouped
        {--date= : Single US session date YYYY-MM-DD}
        {--backfill=0 : Number of trading days to backfill (rate-limited)}';

    protected $description = 'Ingest Polygon Grouped Daily into sniper_daily_bars (free-tier throttled)';

    public function handle(SniperGroupedDailyIngestService $ingest): int
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            $this->warn('Sniper scanner is disabled (VESTIX_SNIPER_SCANNER_ENABLED=false). No-op.');

            return self::SUCCESS;
        }

        $backfill = (int) $this->option('backfill');

        if ($backfill > 0) {
            $this->info("Backfilling {$backfill} trading days via PolygonRateLimiter...");
            $result = $ingest->backfill($backfill);
            $this->info('Upserted rows (sum): '.$result['upserted']);
            $this->line('Dates: '.implode(', ', $result['dates']));

            return self::SUCCESS;
        }

        $result = $ingest->ingestDate($this->option('date') ?: null);
        $this->table(['Field', 'Value'], collect($result)->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : (string) $v])->values()->all());

        return self::SUCCESS;
    }
}
