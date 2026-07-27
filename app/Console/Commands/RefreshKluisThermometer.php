<?php

namespace App\Console\Commands;

use App\Models\VaultSetting;
use App\Services\Kluis\KluisMarketDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshKluisThermometer extends Command
{
    protected $signature = 'vestix:kluis-refresh-thermometer';

    protected $description = 'Ververst de Kluis-thermometer (SMA-200 / live koers) voor alle unieke kern-ETF tickers.';

    public function handle(KluisMarketDataService $marketData): int
    {
        $settingsByTicker = VaultSetting::query()
            ->orderBy('id')
            ->get()
            ->unique(fn (VaultSetting $settings): string => strtoupper(trim((string) $settings->etf_ticker)));

        if ($settingsByTicker->isEmpty()) {
            $this->info('Geen Kluis-instellingen — niets te verversen.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($settingsByTicker as $settings) {
            $ticker = strtoupper(trim((string) $settings->etf_ticker));
            $reading = $marketData->fetchReading($settings, force: true);

            if ($reading === null) {
                $failed++;
                $this->warn("Mislukt: {$ticker}");
                Log::warning('Kluis thermometer refresh failed.', ['ticker' => $ticker]);

                continue;
            }

            $ok++;
            $this->info(sprintf(
                '%s · €%s · SMA-200 €%s · %+.1f%% (%s)',
                $ticker,
                number_format($reading->close, 2, ',', '.'),
                number_format($reading->sma200, 2, ',', '.'),
                $reading->deviationPct,
                $reading->climate->codeLabel(),
            ));
        }

        $summary = ['ok' => $ok, 'failed' => $failed, 'tickers' => $settingsByTicker->count()];
        Log::info('Kluis thermometer refresh completed.', $summary);
        $this->table(['Status', 'Aantal'], [
            ['OK', $ok],
            ['Mislukt', $failed],
        ]);

        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
