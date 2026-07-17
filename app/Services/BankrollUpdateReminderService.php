<?php

namespace App\Services;

use App\Contracts\BankrollSource;
use App\Models\User;
use App\Services\Bankroll\IbkrBankrollSource;
use App\Services\Bankroll\ManualBankrollSource;
use App\Services\Ibkr\IbkrSyncHealth;
use App\Support\TelegramNotifier;
use Illuminate\Support\Carbon;

class BankrollUpdateReminderService
{
    public function __construct(
        private BankrollSnapshotService $bankrollSnapshotService,
        private IbkrSyncHealth $ibkrSyncHealth,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(?Carbon $now = null): array
    {
        $sent = 0;
        $skipped = 0;
        $now ??= now($this->bankrollSnapshotService->timezone());

        User::query()
            ->whereNotNull('trading_bankroll')
            ->cursor()
            ->each(function (User $user) use ($now, &$sent, &$skipped): void {
                if (! $user->hasTelegramConnection()) {
                    $skipped++;

                    return;
                }

                $isIbkrSynced = $user->ibkr_last_success_at !== null
                    || (string) config('vestix.bankroll_tracker.source', 'manual') === 'ibkr'
                    || (string) config('vestix.ibkr.reader', 'stub') === 'flex';

                if ($isIbkrSynced) {
                    if (! $this->ibkrSyncHealth->isStale($user, $now)) {
                        $skipped++;

                        return;
                    }

                    $delivered = TelegramNotifier::sendToUser(
                        $user,
                        '<b>IBKR sync stale</b> — Vestix heeft langer dan '
                        .$this->ibkrSyncHealth->staleAfterHours()
                        .' uur geen verse IBKR-data ontvangen. '
                        .'Automatische sizing/orders zijn geblokkeerd tot `vestix:sync-ibkr` weer lukt.',
                    );
                } else {
                    if (! $this->bankrollSnapshotService->isUpdateDue($user, $now)) {
                        return;
                    }

                    $delivered = TelegramNotifier::sendToUser(
                        $user,
                        '<b>Bankroll update</b> — het is tijd voor je wekelijkse snapshot. '
                        .'Open Vestix op het dashboard en werk je bankroll bij voor de Alpha Tracker.',
                    );
                }

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
