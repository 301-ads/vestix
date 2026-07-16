<?php

namespace App\Console\Commands;

use App\Services\ExecutionDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RunExecutionPrepDigest extends Command
{
    protected $signature = 'vestix:execution-prep-digest {--date= : Datum (Y-m-d) voor handmatige run}';

    protected $description = 'Pre-open Daily Execution Digest (14:30 NL): Stop-Limit plannen voor scouts met Order Plan vandaag.';

    public function handle(ExecutionDigestService $digestService): int
    {
        $dateOption = $this->option('date');
        $today = $dateOption !== null && $dateOption !== ''
            ? Carbon::parse((string) $dateOption, 'Europe/Amsterdam')->startOfDay()
            : null;

        $this->info('Daily Execution Digest (prep) gestart...');

        $summary = $digestService->runPrepDigest($today);

        if ($summary['planned'] === 0 && $summary['skipped'] === 0 && $summary['sent'] === 0) {
            $this->warn('Geen scouts met Order Plan voor vandaag. Zet scouts in je Order Plan (winkelwagen) op Mijn Radar.');
        }

        $this->table(
            ['Status', 'Aantal'],
            [
                ['Plannen', $summary['planned']],
                ['Telegram digests', $summary['sent']],
                ['Overgeslagen', $summary['skipped']],
            ],
        );

        Log::info('Execution Prep Digest completed.', [
            'date' => ($today ?? Carbon::today('Europe/Amsterdam'))->toDateString(),
            ...$summary,
        ]);

        return self::SUCCESS;
    }
}
