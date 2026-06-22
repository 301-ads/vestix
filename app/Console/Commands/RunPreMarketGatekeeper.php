<?php

namespace App\Console\Commands;

use App\Models\Position;
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

        $tradingDay = UsMarketSession::currentUsTradingDay();
        $armedScouts = Position::query()
            ->scout()
            ->armedForEntry($tradingDay)
            ->whereNotNull('entry_price')
            ->get(['id', 'ticker', 'signal_high']);

        $this->info('Pre-Market Gatekeeper gestart...');
        $this->line("US handelsdag: {$tradingDay->toDateString()}");
        $this->line("Scouts op scherp: {$armedScouts->count()}");

        if ($armedScouts->isEmpty()) {
            $this->newLine();
            $this->warn('Geen scouts op scherp voor vandaag.');
            $this->line('Zet scouts op scherp via Mijn Radar → bel-icoon "Op scherp" (vereist ingevulde entry/buy-stop).');

            return self::SUCCESS;
        }

        $missingSignalHigh = $armedScouts->filter(fn (Position $position): bool => $position->signal_high === null);

        if ($missingSignalHigh->isNotEmpty()) {
            $this->warn('Worden overgeslagen (signal_high ontbreekt): '.$missingSignalHigh->pluck('ticker')->join(', '));
        }

        $summary = $gatekeeper->run($tradingDay);

        $this->table(
            ['Metric', 'Aantal'],
            collect($summary)->map(fn (int $count, string $key): array => [$key, $count])->values()->all(),
        );

        Log::info('Pre-market gatekeeper completed.', $summary);

        return self::SUCCESS;
    }
}
