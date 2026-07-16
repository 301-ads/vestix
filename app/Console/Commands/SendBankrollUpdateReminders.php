<?php

namespace App\Console\Commands;

use App\Services\BankrollUpdateReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendBankrollUpdateReminders extends Command
{
    protected $signature = 'vestix:bankroll-update-reminders';

    protected $description = 'Herinnert gebruikers om hun wekelijkse bankroll-snapshot bij te werken.';

    public function handle(BankrollUpdateReminderService $reminderService): int
    {
        $summary = $reminderService->run();

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Verstuurd', $summary['sent']],
                ['Overgeslagen', $summary['skipped']],
            ],
        );

        Log::info('Bankroll update reminders completed.', $summary);

        return self::SUCCESS;
    }
}
