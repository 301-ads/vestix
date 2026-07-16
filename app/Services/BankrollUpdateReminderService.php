<?php

namespace App\Services;

use App\Contracts\BankrollSource;
use App\Models\User;
use App\Services\Bankroll\IbkrBankrollSource;
use App\Services\Bankroll\ManualBankrollSource;
use App\Support\TelegramNotifier;
use Illuminate\Support\Carbon;

class BankrollUpdateReminderService
{
    public function __construct(
        private BankrollSnapshotService $bankrollSnapshotService,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(?Carbon $now = null): array
    {
        $sent = 0;
        $skipped = 0;

        User::query()
            ->whereNotNull('trading_bankroll')
            ->cursor()
            ->each(function (User $user) use ($now, &$sent, &$skipped): void {
                if (! $this->bankrollSnapshotService->isUpdateDue($user, $now)) {
                    return;
                }

                if (! $user->hasTelegramConnection()) {
                    $skipped++;

                    return;
                }

                $delivered = TelegramNotifier::sendToUser(
                    $user,
                    '<b>Bankroll update</b> — het is tijd voor je wekelijkse snapshot. '
                    .'Open Vestix op het dashboard en werk je bankroll bij voor de Alpha Tracker.',
                );

                if ($delivered) {
                    $sent++;
                } else {
                    $skipped++;
                }
            });

        return compact('sent', 'skipped');
    }

    public function configuredSource(): BankrollSource
    {
        return match ((string) config('vestix.bankroll_tracker.source', 'manual')) {
            'ibkr' => app(IbkrBankrollSource::class),
            default => new ManualBankrollSource(0),
        };
    }
}
