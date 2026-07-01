<?php

namespace App\Console\Commands;

use App\Services\MarketOpenBuyStopReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunMarketOpenBuyStopReminders extends Command
{
    protected $signature = 'vestix:market-open-buy-stop-reminders';

    protected $description = 'Stuurt buy-stop reminders vlak na US market open (15:35 NL) voor scouts met geplande reminder.';

    public function handle(MarketOpenBuyStopReminderService $reminderService): int
    {
        $this->info('Market open buy-stop reminders gestart...');

        $summary = $reminderService->run();

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Verstuurd', $summary['sent']],
                ['Overgeslagen (geen Telegram)', $summary['skipped']],
            ],
        );

        Log::info('Market open buy-stop reminders completed.', $summary);

        return self::SUCCESS;
    }
}
