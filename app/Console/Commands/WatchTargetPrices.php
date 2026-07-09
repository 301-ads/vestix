<?php

namespace App\Console\Commands;

use App\Contracts\QuoteProvider;
use App\Enums\Broker;
use App\Jobs\CheckTarget1AlertsJob;
use App\Models\Position;
use App\Support\UsMarketSession;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class WatchTargetPrices extends Command
{
    protected $signature = 'vestix:watch-target-prices
                            {--force : Draai ook buiten het intraday-venster}';

    protected $description = 'Haalt live koersen op voor Revolut Target 1-monitoring (geen SMA/ATR-sync).';

    public function handle(QuoteProvider $quoteProvider): int
    {
        if (! config('vestix.intraday_target_watch.enabled', true)) {
            $this->info('Intraday Target 1-watch is uitgeschakeld.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! UsMarketSession::isIntradayTargetWatchWindow()) {
            $this->info('Buiten intraday Target 1-venster — overgeslagen.');

            return self::SUCCESS;
        }

        $positions = $this->positionsToWatch();

        if ($positions->isEmpty()) {
            $this->info('Geen open Revolut-posities om te monitoren.');

            return self::SUCCESS;
        }

        $delaySeconds = max(0, (int) config('vestix.finnhub.rate_limit_delay', 1));
        $updated = 0;
        $failed = 0;

        foreach ($positions as $position) {
            $price = $quoteProvider->fetchLivePrice($position->ticker);

            if ($price === null) {
                Log::warning('Intraday Target 1 quote failed.', [
                    'position_id' => $position->id,
                    'ticker' => $position->ticker,
                ]);
                $failed++;
            } else {
                $position->update(['latest_close_price' => round($price, 2)]);
                $updated++;
                $this->line("{$position->ticker}: $".number_format($price, 2));
            }

            if ($delaySeconds > 0 && $positions->last()->isNot($position)) {
                sleep($delaySeconds);
            }
        }

        CheckTarget1AlertsJob::dispatch();

        $this->info("Target 1-watch voltooid: {$updated} bijgewerkt, {$failed} mislukt.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Position>
     */
    private function positionsToWatch(): Collection
    {
        return Position::query()
            ->open()
            ->whereNotNull('entry_price')
            ->whereNotNull('current_sl')
            ->whereNull('scaled_out_at')
            ->whereHas('user', fn ($query) => $query->where('primary_broker', Broker::Revolut->value))
            ->with('user')
            ->get()
            ->filter(function (Position $position): bool {
                if ($position->isAutoRunnerBypass()) {
                    return false;
                }

                return $position->target_1_price !== null;
            })
            ->values();
    }
}
