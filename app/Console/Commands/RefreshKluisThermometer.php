<?php

namespace App\Console\Commands;

use App\Models\VaultSetting;
use App\Services\Kluis\KluisMarketDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshKluisThermometer extends Command
{
    protected $signature = 'vestix:kluis-refresh-thermometer';

    protected $description = 'Ververst Kluis-thermometer (SMA-200) én EUR holdings-koers voor alle unieke kern-ETF tickers.';

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
            $holdings = $marketData->fetchHoldingsPrice($ticker, force: true);

            if ($reading === null && $holdings === null) {
                $failed++;
                $this->warn("Mislukt: {$ticker}");
                Log::warning('Kluis market refresh failed.', ['ticker' => $ticker]);

                continue;
            }

            $ok++;
            $parts = [$ticker];

            if ($reading !== null) {
                $parts[] = sprintf(
                    'thermo %s €%s · SMA €%s · %+.1f%% (%s)',
                    $reading->resolvedSymbol ?? 'proxy',
                    number_format($reading->close, 2, ',', '.'),
                    number_format($reading->sma200, 2, ',', '.'),
                    $reading->deviationPct,
                    $reading->climate->codeLabel(),
                );
            }

            if ($holdings !== null) {
                $parts[] = sprintf(
                    'holdings %s €%s',
                    $holdings['resolved_symbol'],
                    number_format($holdings['price'], 2, ',', '.'),
                );
            }

            $this->info(implode(' · ', $parts));
        }

        $summary = ['ok' => $ok, 'failed' => $failed, 'tickers' => $settingsByTicker->count()];
        Log::info('Kluis market refresh completed.', $summary);
        $this->table(['Status', 'Aantal'], [
            ['OK', $ok],
            ['Mislukt', $failed],
        ]);

        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
