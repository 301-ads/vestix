<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ibkr\IbkrSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncIbkrAccount extends Command
{
    protected $signature = 'vestix:sync-ibkr {--user= : Limit sync to a single user id}';

    protected $description = 'Sync read-only IBKR Flex balances/cashflows and optional Client Portal open orders.';

    public function handle(IbkrSyncService $syncService): int
    {
        $userId = $this->option('user');
        $user = null;

        if (filled($userId)) {
            $user = User::query()->find($userId);

            if ($user === null) {
                $this->error("User [{$userId}] not found.");

                return self::FAILURE;
            }
        }

        $summary = $syncService->sync($user);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Success', $summary['success'] ? 'yes' : 'no'],
                ['Users', $summary['users']],
                ['Cashflows imported', $summary['cashflows_imported']],
                ['Cashflows skipped', $summary['cashflows_skipped']],
                ['Error', $summary['error'] ?? '—'],
            ],
        );

        Log::info('IBKR sync completed.', $summary);

        return $summary['success'] ? self::SUCCESS : self::FAILURE;
    }
}
