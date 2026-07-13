<?php

namespace App\Console\Commands;

use App\Services\EarningsExitAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunEarningsExitAlerts extends Command
{
    protected $signature = 'vestix:earnings-exit-alerts {--phase=auto : warning, action, weekend, final, or auto}';

    protected $description = 'Stuurt earnings exit waarschuwingen en actie-triggers via Telegram.';

    public function handle(EarningsExitAlertService $alertService): int
    {
        $phase = (string) $this->option('phase');

        if (! in_array($phase, ['warning', 'action', 'weekend', 'final', 'auto'], true)) {
            $this->error('Ongeldige fase. Gebruik warning, action, weekend, final, of auto.');

            return self::FAILURE;
        }

        $this->info("Earnings exit alerts gestart (fase: {$phase})...");

        $summary = $alertService->run($phase);

        $this->table(
            ['Fase', 'Verstuurd'],
            [
                ['warning (08:00)', $summary['warning']],
                ['action (08:00)', $summary['action']],
                ['weekend (09:00)', $summary['weekend']],
                ['final BMO (21:30)', $summary['final']],
            ],
        );

        Log::info('Earnings exit alerts completed.', [
            'phase' => $phase,
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
