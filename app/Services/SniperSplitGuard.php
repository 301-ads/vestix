<?php

namespace App\Services;

use App\Models\SniperDailyBar;
use App\Models\SniperLiquidityCache;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SniperSplitGuard
{
    public function __construct(
        private readonly PolygonGroupedDailyService $groupedDaily,
        private readonly PolygonDailyBarService $dailyBars,
    ) {}

    /**
     * @return 'ok'|'purge'|'skip'
     */
    public function decide(
        string $ticker,
        string $sessionDate,
        float $newClose,
        ?float $previousClose = null,
    ): string {
        if ($previousClose === null) {
            $previous = SniperDailyBar::query()
                ->where('ticker', $ticker)
                ->where('date', '<', $sessionDate)
                ->orderByDesc('date')
                ->first();

            $previousClose = $previous !== null ? (float) $previous->close : null;
        }

        if ($previousClose === null || $previousClose <= 0 || $newClose <= 0) {
            return 'ok';
        }

        $gapPct = abs(($newClose / $previousClose) - 1.0) * 100;
        $threshold = (float) config('vestix.sniper_scanner.split_gap_pct', 40.0);

        if ($gapPct < $threshold) {
            return 'ok';
        }

        $from = Carbon::parse($sessionDate)->subDays(10)->toDateString();
        $to = Carbon::parse($sessionDate)->addDays(1)->toDateString();
        $splits = $this->groupedDaily->fetchSplits($ticker, $from, $to);

        if ($splits !== []) {
            Log::info('Sniper split confirmed via Polygon.', [
                'ticker' => $ticker,
                'gap_pct' => $gapPct,
                'splits' => $splits,
            ]);

            return 'purge';
        }

        // No API confirmation: do not wipe history (avoids mass false purges).
        Log::warning('Sniper split heuristic skip without API confirmation.', [
            'ticker' => $ticker,
            'gap_pct' => $gapPct,
            'prev_close' => $previousClose,
            'new_close' => $newClose,
        ]);

        return 'skip';
    }

    public function purgeAndBackfill(string $ticker): void
    {
        $ticker = strtoupper(trim($ticker));

        SniperDailyBar::query()->where('ticker', $ticker)->delete();

        SniperLiquidityCache::query()->updateOrCreate(
            ['ticker' => $ticker],
            [
                'bars_ready' => false,
                'split_purged_at' => now(),
            ],
        );

        $lookback = max(55, (int) config('vestix.sniper_scanner.min_bars_for_ready', 50) + 10);
        $payload = $this->dailyBars->fetchRecentBars($ticker, $lookback, $lookback + 5);

        if ($payload === null || ($payload['bars'] ?? []) === []) {
            Log::warning('Sniper split backfill failed.', ['ticker' => $ticker]);

            return;
        }

        $now = now();
        $rows = [];

        foreach ($payload['bars'] as $bar) {
            $rows[] = [
                'ticker' => $ticker,
                'date' => $bar['date'],
                'open' => $bar['open'],
                'high' => $bar['high'],
                'low' => $bar['low'],
                'close' => $bar['close'],
                'volume' => (int) round((float) $bar['volume']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SniperDailyBar::query()->upsert(
            $rows,
            ['ticker', 'date'],
            ['open', 'high', 'low', 'close', 'volume', 'updated_at'],
        );

        $minBars = (int) config('vestix.sniper_scanner.min_bars_for_ready', 50);
        $barCount = SniperDailyBar::query()->where('ticker', $ticker)->count();
        $latest = SniperDailyBar::query()->where('ticker', $ticker)->orderByDesc('date')->first();
        $avg = (int) round((float) SniperDailyBar::query()
            ->where('ticker', $ticker)
            ->where('date', '>=', UsMarketSession::expectedLastCompletedSessionDate()->subDays(45)->toDateString())
            ->avg('volume'));

        SniperLiquidityCache::query()->updateOrCreate(
            ['ticker' => $ticker],
            [
                'bars_ready' => $barCount >= $minBars,
                'avg_volume_30d' => $avg > 0 ? $avg : null,
                'last_volume' => $latest ? (int) $latest->volume : null,
                'metrics_as_of' => $latest?->date?->toDateString(),
                'split_purged_at' => now(),
            ],
        );
    }
}
