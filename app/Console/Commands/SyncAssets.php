<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Position;
use App\Services\AssetSyncService;
use Illuminate\Console\Command;

class SyncAssets extends Command
{
    protected $signature = 'vestix:sync-assets
                            {--ticker= : Sync een specifieke ticker}
                            {--force : Haal branding opnieuw op ook als er al een icoon is}
                            {--delay=12 : Pauze in seconden tussen API-calls}';

    protected $description = 'Haalt ticker-logo\'s op via Polygon en koppelt assets aan posities.';

    public function handle(AssetSyncService $assetSyncService): int
    {
        $ticker = $this->option('ticker');
        $force = (bool) $this->option('force');
        $delay = max(0, (int) $this->option('delay'));

        $tickers = $ticker
            ? collect([Asset::normalizeTicker((string) $ticker)])
            : Position::query()
                ->whereNotNull('ticker')
                ->distinct()
                ->orderBy('ticker')
                ->pluck('ticker')
                ->map(fn (string $value): string => Asset::normalizeTicker($value))
                ->unique()
                ->values();

        if ($tickers->isEmpty()) {
            $this->info('Geen tickers gevonden om te synchroniseren.');

            return self::SUCCESS;
        }

        $this->info("Synchroniseren van {$tickers->count()} ticker(s)...");

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($tickers as $index => $normalizedTicker) {
            if ($index > 0 && $delay > 0) {
                sleep($delay);
            }

            $asset = Asset::query()->firstOrCreate(['ticker' => $normalizedTicker]);

            if (! $force && $asset->hasIcon()) {
                $this->line("Overgeslagen: {$normalizedTicker} (icoon aanwezig)");
                $skipped++;
            } else {
                $asset = $assetSyncService->sync($asset, force: $force);

                if ($asset->hasIcon()) {
                    $this->info("Gesynchroniseerd: {$normalizedTicker}");
                    $synced++;
                } else {
                    $this->warn("Geen branding voor: {$normalizedTicker}");
                    $failed++;
                }
            }

            Position::query()
                ->whereRaw('UPPER(TRIM(ticker)) = ?', [$normalizedTicker])
                ->update(['asset_id' => $asset->id]);
        }

        $this->info("Klaar: {$synced} gesynchroniseerd, {$skipped} overgeslagen, {$failed} zonder branding.");

        return self::SUCCESS;
    }
}
