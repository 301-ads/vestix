<?php

namespace App\Console\Commands;

use App\Contracts\QuoteProvider;
use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\CheckTarget1AlertsJob;
use App\Models\Position;
use App\Support\MarketDataFreshness;
use App\Support\UsMarketSession;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WatchTargetPrices extends Command
{
    protected $signature = 'vestix:watch-target-prices
                            {--force : Draai ook buiten het intraday-venster}';

    protected $description = 'Haalt live koersen op voor alle open posities (geen SMA/ATR-sync).';

    public function handle(QuoteProvider $quoteProvider): int
    {
        if (! config('vestix.intraday_target_watch.enabled', true)) {
            $this->info('Intraday koerswatch is uitgeschakeld.');
            Log::info('vestix:watch-target-prices overgeslagen: uitgeschakeld via config.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! UsMarketSession::isIntradayTargetWatchWindow()) {
            $this->info('Buiten intraday-venster — overgeslagen.');
            Log::info('vestix:watch-target-prices overgeslagen: buiten intraday-venster.');

            return self::SUCCESS;
        }

        $tickers = $this->tickersToWatch();

        if ($tickers->isEmpty()) {
            $this->info('Geen open posities om te monitoren.');
            Log::info('vestix:watch-target-prices overgeslagen: geen open posities.');

            return self::SUCCESS;
        }

        $delaySeconds = max(0, (int) config('vestix.finnhub.rate_limit_delay', 1));
        $updatedPositions = 0;
        $failedTickers = 0;

        Log::info('vestix:watch-target-prices gestart.', [
            'ticker_count' => $tickers->count(),
            'forced' => (bool) $this->option('force'),
        ]);

        foreach ($tickers as $ticker) {
            $price = $quoteProvider->fetchLivePrice($ticker);

            if ($price === null) {
                Log::warning('Intraday quote failed.', ['ticker' => $ticker]);
                $failedTickers++;
            } else {
                $rounded = round($price, 2);
                $updatedPositions += Position::query()
                    ->open()
                    ->where('ticker', $ticker)
                    ->update(['latest_close_price' => $rounded]);

                $this->line("{$ticker}: $".number_format($rounded, 2));
            }

            if ($delaySeconds > 0 && $tickers->last() !== $ticker) {
                sleep($delaySeconds);
            }
        }

        if ($updatedPositions > 0) {
            MarketDataFreshness::markIntradayQuoteFetch();
            CheckTarget1AlertsJob::dispatch();
            CheckPositionAlertTriggersJob::dispatch();
        }

        $this->info("Intraday koerswatch voltooid: {$updatedPositions} posities bijgewerkt, {$failedTickers} ticker(s) mislukt.");

        Log::info('vestix:watch-target-prices voltooid.', [
            'updated_positions' => $updatedPositions,
            'failed_tickers' => $failedTickers,
        ]);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function tickersToWatch(): Collection
    {
        return Position::query()
            ->open()
            ->whereNotNull('entry_price')
            ->pluck('ticker')
            ->map(fn (string $ticker): string => strtoupper(trim($ticker)))
            ->unique()
            ->values();
    }
}
