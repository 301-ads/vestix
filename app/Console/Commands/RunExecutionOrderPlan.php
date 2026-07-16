<?php

namespace App\Console\Commands;

use App\Services\ExecutionDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RunExecutionOrderPlan extends Command
{
    protected $signature = 'vestix:execution-order-plan {--date= : Datum (Y-m-d) voor handmatige run}';

    protected $description = 'Post-open Gap Guard + Telegram Order Plan (15:31 NL) voor scouts met Order Plan vandaag.';

    public function handle(ExecutionDigestService $digestService): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption !== null && $dateOption !== ''
            ? Carbon::parse((string) $dateOption, 'Europe/Amsterdam')->startOfDay()
            : null;

        $this->info('Execution Order Plan gestart...');

        $summary = $digestService->run($today);

        $dueCount = $summary['classified'] + $summary['skipped'];

        if ($dueCount === 0) {
            $this->warn('Geen scouts met Order Plan voor vandaag. Zet de bel/toggle op Mijn Radar.');
        }

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Classified', $summary['classified']],
                ['Safe', $summary['safe']],
                ['Cancelled', $summary['cancelled']],
                ['Telegram digests', $summary['sent']],
                ['Overgeslagen', $summary['skipped']],
            ],
        );

        Log::info('Execution Order Plan completed.', [
            'date' => ($today ?? Carbon::today('Europe/Amsterdam'))->toDateString(),
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
