<?php

namespace App\Console\Commands;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\User;
use App\Services\SniperScanService;
use Illuminate\Console\Command;

class RunSniperScan extends Command
{
    protected $signature = 'vestix:sniper-scan
        {--date= : US session date YYYY-MM-DD}
        {--dry-run : Count hits without creating scouts}
        {--skip-ingest : Skip Grouped Daily ingest (use existing cache)}
        {--no-digest : Do not send digest alert}';

    protected $description = 'Native EOD sniper scan (default ON; disable with VESTIX_SNIPER_SCANNER_ENABLED=false)';

    public function handle(SniperScanService $scan, AlertDispatcher $alerts): int
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            $this->warn('Sniper scanner is disabled (VESTIX_SNIPER_SCANNER_ENABLED=false). No-op.');

            return self::SUCCESS;
        }

        $summary = $scan->run(
            dryRun: (bool) $this->option('dry-run'),
            date: $this->option('date') ?: null,
            skipIngest: (bool) $this->option('skip-ingest'),
        );

        $this->table(
            ['Metric', 'Value'],
            collect($summary)
                ->except(['coverage'])
                ->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '')])
                ->values()
                ->all(),
        );

        if (isset($summary['coverage']) && is_array($summary['coverage'])) {
            $this->info(sprintf(
                'Coverage: bars_ready=%d with_cap=%d cache_rows=%d',
                $summary['coverage']['bars_ready'] ?? 0,
                $summary['coverage']['with_cap'] ?? 0,
                $summary['coverage']['cache_rows'] ?? 0,
            ));
        }

        if (! $this->option('no-digest') && ! $this->option('dry-run') && ($summary['reason'] ?? null) === null) {
            $this->sendDigest($alerts, $summary);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function sendDigest(AlertDispatcher $alerts, array $summary): void
    {
        $ownerId = (int) config('vestix.sniper_scanner.owner_user_id');
        $owner = User::query()->find($ownerId);

        if (! $owner instanceof User) {
            return;
        }

        $message = sprintf(
            "Vestix Ochtendscan Voltooid\nScanned: %s | Liquide: %s | Hits: %s | Created: %s\nEarnings blocked: %s | Capped: %s | Splits purged: %s\nBekijk Visuele Review in Mijn Radar.",
            number_format((int) ($summary['scanned'] ?? 0)),
            number_format((int) ($summary['liquid'] ?? 0)),
            number_format((int) ($summary['math_hits'] ?? 0)),
            number_format((int) ($summary['created'] ?? 0)),
            number_format((int) ($summary['earnings_blocked'] ?? 0)),
            number_format((int) ($summary['earnings_capped'] ?? 0)),
            number_format((int) ($summary['splits_purged'] ?? 0)),
        );

        $alerts->dispatchUserEvent($owner, AlertEventType::SniperScanDigest, $message);
    }
}
