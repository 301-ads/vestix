<?php

namespace App\Services;

use App\Models\SniperDailyBar;
use App\Models\SniperLiquidityCache;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SniperGroupedDailyIngestService
{
    public function __construct(
        private readonly PolygonGroupedDailyService $groupedDaily,
        private readonly SniperSplitGuard $splitGuard,
    ) {}

    /**
     * @return array{date: string, upserted: int, splits_purged: int, skipped: bool, reason?: string}
     */
    public function ingestDate(?string $date = null, bool $refreshMetrics = true): array
    {
        $sessionDate = $date
            ? Carbon::parse($date, 'America/New_York')->toDateString()
            : UsMarketSession::expectedLastCompletedSessionDate()->toDateString();

        $rows = $this->groupedDaily->fetchForDate($sessionDate);

        if ($rows === null) {
            return [
                'date' => $sessionDate,
                'upserted' => 0,
                'splits_purged' => 0,
                'skipped' => true,
                'reason' => 'grouped_daily_unavailable',
            ];
        }

        $splitsPurged = 0;
        $skippedUpserts = 0;
        $now = now();
        $chunk = [];
        $previousSession = UsMarketSession::previousTradingDay(Carbon::parse($sessionDate, 'America/New_York'))
            ->toDateString();
        $previousCloses = SniperDailyBar::query()
            ->where('date', $previousSession)
            ->pluck('close', 'ticker')
            ->map(fn ($close): float => (float) $close)
            ->all();

        foreach ($rows as $row) {
            $previousClose = $previousCloses[$row['ticker']] ?? null;
            $decision = $this->splitGuard->decide(
                $row['ticker'],
                $sessionDate,
                $row['close'],
                $previousClose,
            );

            if ($decision === 'purge') {
                $this->splitGuard->purgeAndBackfill($row['ticker']);
                $splitsPurged++;
            } elseif ($decision === 'skip') {
                // Extreme gap without confirmed split: keep history, skip today's bar.
                $skippedUpserts++;

                continue;
            }

            $chunk[] = [
                'ticker' => $row['ticker'],
                'date' => $sessionDate,
                'open' => $row['open'],
                'high' => $row['high'],
                'low' => $row['low'],
                'close' => $row['close'],
                'volume' => $row['volume'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($chunk) >= 500) {
                $this->upsertBars($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->upsertBars($chunk);
        }

        if ($refreshMetrics) {
            $this->refreshLiquidityMetrics($sessionDate);
            $this->pruneOldBars();
        }

        Log::info('Sniper grouped daily ingested.', [
            'date' => $sessionDate,
            'upserted' => count($rows) - $skippedUpserts,
            'splits_purged' => $splitsPurged,
            'skipped_upserts' => $skippedUpserts,
        ]);

        return [
            'date' => $sessionDate,
            'upserted' => count($rows) - $skippedUpserts,
            'splits_purged' => $splitsPurged,
            'skipped' => false,
        ];
    }

    /**
     * @return array{dates: list<string>, upserted: int}
     */
    public function backfill(int $tradingDays): array
    {
        $tradingDays = max(1, min(80, $tradingDays));
        $cursor = UsMarketSession::expectedLastCompletedSessionDate();
        $dates = [];
        $upserted = 0;
        $latestDate = $cursor->toDateString();

        for ($i = 0; $i < $tradingDays; $i++) {
            $dates[] = $cursor->toDateString();
            $result = $this->ingestDate($cursor->toDateString(), refreshMetrics: false);
            $upserted += $result['upserted'];
            $cursor = UsMarketSession::previousTradingDay($cursor);
        }

        $this->refreshLiquidityMetrics($latestDate);
        $this->pruneOldBars();

        return [
            'dates' => $dates,
            'upserted' => $upserted,
        ];
    }

    /**
     * Fetch only missing session dates until distinct bar dates >= $minDays (SMA50 needs 50).
     *
     * @return array{fetched: list<string>, skipped_existing: int, upserted: int, distinct_dates: int, bars_ready: int}
     */
    public function ensureTradingDays(int $minDays): array
    {
        $minDays = max(1, min(80, $minDays));
        $existing = [];

        foreach (SniperDailyBar::query()->distinct()->pluck('date') as $date) {
            $key = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();
            $existing[$key] = true;
        }

        $cursor = UsMarketSession::expectedLastCompletedSessionDate();
        $latestDate = $cursor->toDateString();
        $fetched = [];
        $skippedExisting = 0;
        $upserted = 0;
        $guard = 0;

        while (count($existing) < $minDays && $guard < 120) {
            $date = $cursor->toDateString();

            if (isset($existing[$date])) {
                $skippedExisting++;
            } else {
                $result = $this->ingestDate($date, refreshMetrics: false);
                $upserted += $result['upserted'];

                if (! ($result['skipped'] ?? false) && $result['upserted'] > 0) {
                    $existing[$date] = true;
                    $fetched[] = $date;
                }
            }

            $cursor = UsMarketSession::previousTradingDay($cursor);
            $guard++;
        }

        $metrics = $this->recomputeLiquidityMetrics($latestDate);
        $this->pruneOldBars();

        return [
            'fetched' => $fetched,
            'skipped_existing' => $skippedExisting,
            'upserted' => $upserted,
            'distinct_dates' => count($existing),
            'bars_ready' => $metrics['bars_ready'],
        ];
    }

    /**
     * Recompute liquidity cache from existing bars (no Polygon calls).
     *
     * @return array{tickers: int, bars_ready: int}
     */
    public function recomputeLiquidityMetrics(?string $asOf = null): array
    {
        $sessionDate = $asOf
            ?? SniperDailyBar::query()->max('date')
            ?? UsMarketSession::expectedLastCompletedSessionDate()->toDateString();

        if ($sessionDate instanceof Carbon) {
            $sessionDate = $sessionDate->toDateString();
        }

        $this->refreshLiquidityMetrics((string) $sessionDate);

        return [
            'tickers' => SniperLiquidityCache::query()->count(),
            'bars_ready' => SniperLiquidityCache::query()->where('bars_ready', true)->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertBars(array $rows): void
    {
        SniperDailyBar::query()->upsert(
            $rows,
            ['ticker', 'date'],
            ['open', 'high', 'low', 'close', 'volume', 'updated_at'],
        );
    }

    public function refreshLiquidityMetrics(string $sessionDate): void
    {
        $minBars = (int) config('vestix.sniper_scanner.min_bars_for_ready', 50);
        $avgLookbackDays = 45; // ~30 trading days for avg volume
        $allowlist = array_map('strtoupper', config('vestix.sniper_scanner.etf_allowlist', []));
        $avgSince = Carbon::parse($sessionDate)->subDays($avgLookbackDays)->toDateString();

        // bars_ready = total stored history per ticker (retention already caps table size).
        // Do NOT use a short calendar window — that falsely zeros bars_ready after daily ingest.
        $barCounts = SniperDailyBar::query()
            ->select(['ticker', DB::raw('COUNT(*) as bar_count')])
            ->groupBy('ticker')
            ->pluck('bar_count', 'ticker');

        $avgVolumes = SniperDailyBar::query()
            ->select(['ticker', DB::raw('AVG(volume) as avg_volume_30d')])
            ->where('date', '>=', $avgSince)
            ->groupBy('ticker')
            ->pluck('avg_volume_30d', 'ticker');

        $latestVolumes = SniperDailyBar::query()
            ->where('date', $sessionDate)
            ->pluck('volume', 'ticker');

        $tickers = $barCounts->keys()->merge($avgVolumes->keys())->merge($latestVolumes->keys())->unique();
        $now = now();
        $readyCount = 0;

        foreach ($tickers as $ticker) {
            $ticker = (string) $ticker;
            $existing = SniperLiquidityCache::query()->find($ticker);
            $assetType = $existing?->asset_type;

            if ($assetType === null && in_array($ticker, $allowlist, true)) {
                $assetType = 'ETF';
            }

            $barCount = (int) ($barCounts[$ticker] ?? 0);
            $barsReady = $barCount >= $minBars;

            if ($barsReady) {
                $readyCount++;
            }

            SniperLiquidityCache::query()->updateOrCreate(
                ['ticker' => $ticker],
                [
                    'asset_type' => $assetType,
                    'avg_volume_30d' => isset($avgVolumes[$ticker])
                        ? (int) round((float) $avgVolumes[$ticker])
                        : $existing?->avg_volume_30d,
                    'last_volume' => isset($latestVolumes[$ticker])
                        ? (int) $latestVolumes[$ticker]
                        : $existing?->last_volume,
                    'enabled' => $existing?->enabled ?? true,
                    'bars_ready' => $barsReady,
                    'metrics_as_of' => $sessionDate,
                    'updated_at' => $now,
                ],
            );
        }

        Log::info('Sniper liquidity metrics refreshed.', [
            'as_of' => $sessionDate,
            'tickers' => $tickers->count(),
            'bars_ready' => $readyCount,
            'min_bars' => $minBars,
        ]);
    }

    private function pruneOldBars(): void
    {
        $retention = (int) config('vestix.sniper_scanner.bars_retention_days', 60);
        $cutoff = UsMarketSession::expectedLastCompletedSessionDate()
            ->subDays($retention + 10)
            ->toDateString();

        SniperDailyBar::query()->where('date', '<', $cutoff)->delete();
    }
}
