<?php

namespace App\Console\Commands;

use App\Services\OrderPlanPremarketPruneService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RunOrderPlanPremarketPrune extends Command
{
    protected $signature = 'vestix:order-plan-premarket-prune {--date= : Datum (Y-m-d) voor handmatige run}';

    protected $description = 'Pre-market Order Plan herziening: drop scouts onder SMA 20 vóór de Execution Digest.';

    public function handle(OrderPlanPremarketPruneService $pruneService): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption !== null && $dateOption !== ''
            ? Carbon::parse((string) $dateOption, 'Europe/Amsterdam')->startOfDay()
            : null;

        $this->info('Order Plan pre-market prune gestart...');

        $summary = $pruneService->run($today);

        if ($summary['checked'] === 0) {
            $this->warn('Geen scouts met Order Plan voor vandaag.');
        }

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Gecontroleerd', $summary['checked']],
                ['Verwijderd', $summary['pruned']],
                ['Behouden', $summary['kept']],
                ['Geen quote', $summary['unavailable']],
                ['Telegram verzonden', $summary['sent']],
                ['Overgeslagen', $summary['skipped']],
            ],
        );

        Log::info('Order Plan premarket prune completed.', [
            'date' => ($today ?? Carbon::today('Europe/Amsterdam'))->toDateString(),
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
