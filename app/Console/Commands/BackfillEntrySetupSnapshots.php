<?php

namespace App\Console\Commands;

use App\Services\EntrySetupSnapshotBackfillService;
use Illuminate\Console\Command;

class BackfillEntrySetupSnapshots extends Command
{
    protected $signature = 'vestix:backfill-entry-setup-snapshots
                            {--user= : Limit to a single user ID}
                            {--dry-run : Show counts without writing}';

    protected $description = 'Backfill entry_setup_* grades for open/closed positions from buy-stop review or last_setup_score (live grade rules).';

    public function handle(EntrySetupSnapshotBackfillService $backfill): int
    {
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $backfill->backfill($userId, $dryRun);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $dryRun ? 'dry-run' : 'write'],
                ['Updated', (string) $result['updated']],
                ['Skipped', (string) $result['skipped']],
            ],
        );

        return self::SUCCESS;
    }
}
