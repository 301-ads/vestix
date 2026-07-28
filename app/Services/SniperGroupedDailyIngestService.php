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
    public function ingestDate(?string $date = null): array
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
            $purge = $this->splitGuard->shouldPurge(
                $row['ticker'],
                $sessionDate,
                $row['close'],
                $previousClose,
            );

            if ($purge) {
                $this->splitGuard->purgeAndBackfill($row['ticker']);
                $splitsPurged++;
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

        $this->refreshLiquidityMetrics($sessionDate);
        $this->pruneOldBars();

        Log::info('Sniper grouped daily ingested.', [
            'date' => $sessionDate,
            'upserted' => count($rows),
            'splits_purged' => $splitsPurged,
        ]);

        return [
            'date' => $sessionDate,
            'upserted' => count($rows),
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

        for ($i = 0; $i < $tradingDays; $i++) {
            $dates[] = $cursor->toDateString();
            $result = $this->ingestDate($cursor->toDateString());
            $upserted += $result['upserted'];
            $cursor = UsMarketSession::previousTradingDay($cursor);
        }

        return [
            'dates' => $dates,
            'upserted' => $upserted,
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

    private function refreshLiquidityMetrics(string $sessionDate): void
    {
        $minBars = (int) config('vestix.sniper_scanner.min_bars_for_ready', 50);
        $allowlist = array_map('strtoupper', config('vestix.sniper_scanner.etf_allowlist', []));

        $stats = SniperDailyBar::query()
            ->select([
                'ticker',
                DB::raw('AVG(volume) as avg_volume_30d'),
                DB::raw('COUNT(*) as bar_count'),
            ])
            ->where('date', '>=', Carbon::parse($sessionDate)->subDays(45)->toDateString())
            ->groupBy('ticker')
            ->get();

        $latestVolumes = SniperDailyBar::query()
            ->where('date', $sessionDate)
            ->pluck('volume', 'ticker');

        $now = now();

        foreach ($stats as $stat) {
            $ticker = (string) $stat->ticker;
            $existing = SniperLiquidityCache::query()->find($ticker);
            $assetType = $existing?->asset_type;

            if ($assetType === null && in_array($ticker, $allowlist, true)) {
                $assetType = 'ETF';
            }

            SniperLiquidityCache::query()->updateOrCreate(
                ['ticker' => $ticker],
                [
                    'asset_type' => $assetType,
                    'avg_volume_30d' => (int) round((float) $stat->avg_volume_30d),
                    'last_volume' => isset($latestVolumes[$ticker]) ? (int) $latestVolumes[$ticker] : $existing?->last_volume,
                    'enabled' => $existing?->enabled ?? true,
                    'bars_ready' => (int) $stat->bar_count >= $minBars,
                    'metrics_as_of' => $sessionDate,
                    'updated_at' => $now,
                ],
            );
        }
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
