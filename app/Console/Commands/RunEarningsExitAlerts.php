<?php

namespace App\Console\Commands;

use App\Services\EarningsExitAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunEarningsExitAlerts extends Command
{
    protected $signature = 'vestix:earnings-exit-alerts {--phase=auto : warning, action, or auto}';

    protected $description = 'Stuurt earnings exit waarschuwingen (T-2) en actie-triggers (T-1) via Telegram.';

    public function handle(EarningsExitAlertService $alertService): int
    {
        $phase = (string) $this->option('phase');

        if (! in_array($phase, ['warning', 'action', 'auto'], true)) {
            $this->error('Ongeldige fase. Gebruik warning, action, of auto.');

            return self::FAILURE;
        }

        $this->info("Earnings exit alerts gestart (fase: {$phase})...");

        $summary = $alertService->run($phase);

        $this->table(
            ['Fase', 'Verstuurd'],
            [
                ['warning (08:00)', $summary['warning']],
                ['action (15:00)', $summary['action']],
            ],
        );

        Log::info('Earnings exit alerts completed.', [
            'phase' => $phase,
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
