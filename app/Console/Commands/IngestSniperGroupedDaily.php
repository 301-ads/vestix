<?php

namespace App\Console\Commands;

use App\Services\SniperGroupedDailyIngestService;
use Illuminate\Console\Command;

class IngestSniperGroupedDaily extends Command
{
    protected $signature = 'vestix:sniper-ingest-grouped
        {--date= : Single US session date YYYY-MM-DD}
        {--backfill=0 : Number of trading days to backfill (rate-limited)}
        {--ensure-days=0 : Only fetch missing days until this many distinct dates exist}';

    protected $description = 'Ingest Polygon Grouped Daily into sniper_daily_bars (free-tier throttled)';

    public function handle(SniperGroupedDailyIngestService $ingest): int
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            $this->warn('Sniper scanner is disabled (VESTIX_SNIPER_SCANNER_ENABLED=false). No-op.');

            return self::SUCCESS;
        }

        $ensureDays = (int) $this->option('ensure-days');

        if ($ensureDays > 0) {
            $this->info("Ensuring at least {$ensureDays} distinct trading days (fetches only missing dates)...");
            $result = $ingest->ensureTradingDays($ensureDays);
            $this->table(
                ['Field', 'Value'],
                [
                    ['fetched', implode(', ', $result['fetched']) ?: '(none)'],
                    ['skipped_existing', (string) $result['skipped_existing']],
                    ['upserted', (string) $result['upserted']],
                    ['distinct_dates', (string) $result['distinct_dates']],
                    ['bars_ready', (string) $result['bars_ready']],
                ],
            );

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
