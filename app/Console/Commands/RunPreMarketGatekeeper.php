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

    protected $description = 'Controleert pre-market prijzen voor alle scouts op de watchlist.';

    public function handle(PreMarketGatekeeperService $gatekeeper): int
    {
        if (! $this->option('force') && ! UsMarketSession::isGatekeeperWindow()) {
            $this->warn('Buiten gatekeeper-venster ('.UsMarketSession::gatekeeperWindowLabel().'). Gebruik --force om toch te draaien.');
            Log::info('Pre-market gatekeeper skipped — outside gatekeeper window.');

            return self::SUCCESS;
        }

        if (! UsMarketSession::isUsTradingDay()) {
            $this->warn('Geen US handelsdag — gatekeeper overgeslagen.');
            Log::info('Pre-market gatekeeper skipped — not a US trading day.');

            return self::SUCCESS;
        }

        $tradingDay = UsMarketSession::currentUsTradingDay();
        $scoutCount = Position::query()->scout()->count();

        $this->info('Pre-Market Gatekeeper gestart...');
        $this->line("US handelsdag: {$tradingDay->toDateString()}");
        $this->line("Scouts op watchlist: {$scoutCount}");

        if ($scoutCount === 0) {
            $this->newLine();
            $this->warn('Geen scouts op de watchlist.');

            return self::SUCCESS;
        }

        $expectedCalls = $gatekeeper->estimateApiCalls();
        $rateLimitDelay = (int) config('vestix.polygon.rate_limit_delay', 13);
        $estimatedSeconds = $expectedCalls * $rateLimitDelay;
        $capability = \App\Support\PremarketQuoteCapability::assess();

        $this->line("Verwachte API-calls: {$expectedCalls}");
        $this->line("Geschatte duur: ~{$estimatedSeconds}s");

        if (! \App\Support\PremarketQuoteCapability::hasLivePremarketSource()) {
            $this->newLine();
            $this->warn($capability['message']);
            $this->line('Scouts worden wel gescand, maar geven waarschijnlijk status unavailable (geen valse gap-up alerts).');
            $this->line('Voor live pre-market: upgrade Polygon naar een plan met Stocks Snapshot / Last Trade.');
        }

        $startedAt = microtime(true);
        $summary = $gatekeeper->run($tradingDay);
        $elapsedSeconds = (int) round(microtime(true) - $startedAt);

        $this->line("Voltooid in {$elapsedSeconds}s");

        $this->table(
            ['Metric', 'Aantal'],
            collect($summary)->map(fn (int $count, string $key): array => [$key, $count])->values()->all(),
        );

        if ($summary['checked'] > 0 && $summary['unavailable'] === $summary['checked']) {
            $this->newLine();
            $this->warn('Alle scans unavailable — er is geen betrouwbare live pre-market quote op het huidige API-plan.');
            $this->line(\App\Support\PremarketQuoteCapability::assess()['message']);
        }

        Log::info('Pre-market gatekeeper completed.', [
            ...$summary,
            'expected_api_calls' => $expectedCalls,
            'elapsed_seconds' => $elapsedSeconds,
        ]);

        return self::SUCCESS;
    }
}
