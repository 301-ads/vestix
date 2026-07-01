<?php

namespace App\Console\Commands;

use App\Services\MarketOpenBuyStopReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunMarketOpenBuyStopReminders extends Command
{
    protected $signature = 'vestix:market-open-buy-stop-reminders {--date= : Datum (Y-m-d) voor handmatige run}';

    protected $description = 'Stuurt buy-stop reminders vlak na US market open (15:35 NL) voor scouts met geplande reminder.';

    public function handle(MarketOpenBuyStopReminderService $reminderService): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption !== null && $dateOption !== ''
            ? \Illuminate\Support\Carbon::parse((string) $dateOption, 'Europe/Amsterdam')->startOfDay()
            : null;

        $this->info('Market open buy-stop reminders gestart...');

        $summary = $reminderService->run($today);

        $dueCount = $summary['sent'] + $summary['skipped'];

        if ($dueCount === 0) {
            $this->warn('Geen scouts met reminder voor vandaag. Controleer of de toggle/bel actief is en de datum klopt.');
        }

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Verstuurd', $summary['sent']],
                ['Overgeslagen (geen Telegram)', $summary['skipped']],
            ],
        );

        Log::info('Market open buy-stop reminders completed.', [
            'date' => ($today ?? \Illuminate\Support\Carbon::today('Europe/Amsterdam'))->toDateString(),
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
