<?php

namespace App\Console\Commands;

use App\Services\Kluis\KluisMonthReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendKluisMonthReminders extends Command
{
    protected $signature = 'vestix:kluis-month-reminders';

    protected $description = 'Herinnert gebruikers die deze maand nog geen Kluis-bevel hebben bevestigd.';

    public function handle(KluisMonthReminderService $reminderService): int
    {
        $summary = $reminderService->run();

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Verstuurd', $summary['sent']],
                ['Overgeslagen', $summary['skipped']],
            ],
        );

        Log::info('Kluis month reminders completed.', $summary);

        return self::SUCCESS;
    }
}
