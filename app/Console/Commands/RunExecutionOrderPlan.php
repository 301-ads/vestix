<?php

namespace App\Console\Commands;

use App\Services\ExecutionDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RunExecutionOrderPlan extends Command
{
    protected $signature = 'vestix:execution-order-plan {--date= : Datum (Y-m-d) voor handmatige run}';

    protected $description = 'Post-open Gap Reality Check (15:31 NL): waarschuwing bij overgeslagen Stop-Limits.';

    public function handle(ExecutionDigestService $digestService): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption !== null && $dateOption !== ''
            ? Carbon::parse((string) $dateOption, 'Europe/Amsterdam')->startOfDay()
            : null;

        $this->info('Gap Reality Check gestart...');

        $summary = $digestService->run($today);

        $dueCount = $summary['classified'] + $summary['skipped'];

        if ($dueCount === 0) {
            $this->warn('Geen scouts met Order Plan voor vandaag. Zet scouts in je Order Plan (winkelwagen) op Mijn Radar.');
        }

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Classified', $summary['classified']],
                ['OK (onder limit)', $summary['safe']],
                ['Overgeslagen / cancelled', $summary['cancelled']],
                ['Telegram waarschuwingen', $summary['sent']],
                ['Overgeslagen users', $summary['skipped']],
            ],
        );

        Log::info('Gap Reality Check completed.', [
            'date' => ($today ?? Carbon::today('Europe/Amsterdam'))->toDateString(),
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
