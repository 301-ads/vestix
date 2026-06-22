<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Position;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EarningsCalendarSyncService
{
    public function __construct(
        private readonly FinnhubService $finnhubService,
        private readonly AssetSyncService $assetSyncService,
    ) {}

    /**
     * @return array{synced: int, skipped: int, failed: int}
     */
    public function syncTrackedTickers(): array
    {
        $tickers = Position::query()
            ->tracked()
            ->distinct()
            ->pluck('ticker')
            ->map(fn (string $ticker): string => Asset::normalizeTicker($ticker))
            ->unique()
            ->values();

        $summary = [
            'synced' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($tickers as $ticker) {
            $result = $this->syncTicker($ticker);

            if ($result === 'synced') {
                $summary['synced']++;
            } elseif ($result === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    public function syncTicker(string $ticker, bool $force = false): string
    {
        $ticker = Asset::normalizeTicker($ticker);
        $cacheKey = "vestix:earnings-sync:{$ticker}";

        if (! $force && Cache::has($cacheKey)) {
            return 'skipped';
        }

        $asset = $this->assetSyncService->ensureForTicker($ticker);
        $earnings = $this->finnhubService->fetchNextEarnings($ticker);

        Cache::put($cacheKey, true, now()->addDay());

        if ($earnings === null) {
            Log::info('No upcoming earnings found for ticker.', ['ticker' => $ticker]);

            $asset->update([
                'next_earnings_date' => null,
                'next_earnings_hour' => null,
                'earnings_fetched_at' => now(),
            ]);

            return 'synced';
        }

        $asset->update([
            'next_earnings_date' => $earnings['date'],
            'next_earnings_hour' => $earnings['hour'],
            'earnings_fetched_at' => now(),
        ]);

        return 'synced';
    }
}
