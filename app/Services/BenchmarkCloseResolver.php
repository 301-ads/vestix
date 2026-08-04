<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class BenchmarkCloseResolver
{
    public function __construct(
        private DailyBarProvider $dailyBars,
    ) {}

    public function benchmarkTicker(): string
    {
        return strtoupper((string) config('vestix.bankroll_tracker.benchmark_ticker', 'SPY'));
    }

    public function resolveCloseForDate(Carbon $date): ?float
    {
        $ticker = $this->benchmarkTicker();
        $targetDate = $date->copy()->timezone('America/New_York')->toDateString();
        $cacheKey = "vestix:benchmark-close:{$ticker}:{$targetDate}";

        $cached = Cache::get($cacheKey);
        if (is_float($cached) || is_int($cached)) {
            return (float) $cached;
        }

        $lookbackDays = max(14, (int) $date->diffInDays(now()) + 10);
        $barsPayload = $this->dailyBars->fetchRecentBars($ticker, $lookbackDays, 120);

        if ($barsPayload === null) {
            return null;
        }

        $closeOnOrBefore = null;
        $matchedDate = null;

        foreach ($barsPayload['bars'] as $bar) {
            if ($bar['date'] <= $targetDate) {
                $closeOnOrBefore = (float) $bar['close'];
                $matchedDate = $bar['date'];
            }
        }

        if ($closeOnOrBefore === null) {
            return null;
        }

        // Only long-cache exact session closes. A fallback to an older bar (holiday /
        // bar not published yet) must not stick for a full day under today's key.
        $ttl = $matchedDate === $targetDate
            ? now()->addDay()
            : now()->addMinutes(30);

        Cache::put($cacheKey, $closeOnOrBefore, $ttl);

        return $closeOnOrBefore;
    }

    /**
     * Close for the last completed US RTH session on/before the given moment.
     * Before today's close (and on weekends), this is the previous trading day —
     * never an incomplete "today" bar.
     */
    public function resolveTradingDayClose(Carbon $date): ?float
    {
        $asOf = $date->copy()->timezone('America/New_York');
        $session = UsMarketSession::expectedLastCompletedSessionDate($asOf);

        $requested = $asOf->copy()->startOfDay();
        while ($requested->isWeekend()) {
            $requested->subDay();
        }

        if ($requested->greaterThan($session)) {
            $requested = $session->copy();
        }

        return $this->resolveCloseForDate($requested);
    }
}
