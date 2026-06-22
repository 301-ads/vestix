<?php

namespace App\Console\Commands;

use App\Services\PreMarketGatekeeperService;
use App\Support\UsMarketSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunPreMarketGatekeeper extends Command
{
    protected $signature = 'vestix:premarket-gatekeeper {--force : Draai ook buiten het gatekeeper-venster}';

    protected $description = 'Controleert pre-market prijzen voor scouts die vandaag op scherp staan.';

    public function handle(PreMarketGatekeeperService $gatekeeper): int
    {
        if (! $this->option('force') && ! UsMarketSession::isGatekeeperWindow()) {
            $this->warn('Buiten gatekeeper-venster (14:55–15:10 Amsterdam). Gebruik --force om toch te draaien.');
            Log::info('Pre-market gatekeeper skipped — outside gatekeeper window.');

            return self::SUCCESS;
        }

        if (! UsMarketSession::isUsTradingDay()) {
            $this->warn('Geen US handelsdag — gatekeeper overgeslagen.');
            Log::info('Pre-market gatekeeper skipped — not a US trading day.');

            return self::SUCCESS;
        }

        $this->info('Pre-Market Gatekeeper gestart...');

        $summary = $gatekeeper->run();

        $this->table(
            ['Metric', 'Aantal'],
            collect($summary)->map(fn (int $count, string $key): array => [$key, $count])->values()->all(),
        );

        Log::info('Pre-market gatekeeper completed.', $summary);

        return self::SUCCESS;
    }
}
