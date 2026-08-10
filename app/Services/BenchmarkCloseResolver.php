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

    /**
     * Exact session closes for chart densify (Y-m-d => close), inclusive.
     *
     * Hot path (Livewire/web): cache + already-resolved daily keys only — never fan out
     * to Polygon/Finnhub/AV with rate-limit sleeps (that exceeds PHP-FPM max_execution_time
     * and leaves AlphaTrackerChart blank while stats still render).
     *
     * @return array<string, float>
     */
    public function closesBetween(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->copy()->timezone('America/New_York')->toDateString();
        $toDate = $to->copy()->timezone('America/New_York')->toDateString();

        if ($fromDate > $toDate) {
            return [];
        }

        $ticker = $this->benchmarkTicker();
        $rangeKey = "vestix:benchmark-closes:{$ticker}:{$fromDate}:{$toDate}";

        $cached = Cache::get($rangeKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $fromDailyCache = $this->cachedClosesInRange($ticker, $fromDate, $toDate);

        if (! $this->shouldFetchRemoteCloses($rangeKey)) {
            return $fromDailyCache;
        }

        $closes = $this->fetchClosesBetween($ticker, $fromDate, $toDate);

        if ($closes === []) {
            Cache::put("{$rangeKey}:miss", true, now()->addMinutes(10));

            return $fromDailyCache;
        }

        Cache::put($rangeKey, $closes, now()->addHours(12));

        foreach ($closes as $date => $close) {
            Cache::put("vestix:benchmark-close:{$ticker}:{$date}", $close, now()->addDay());
        }

        return $closes;
    }

    /**
     * Force a remote range fetch (console/queue) and populate caches for the web chart path.
     *
     * @return array<string, float>
     */
    public function warmClosesBetween(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->copy()->timezone('America/New_York')->toDateString();
        $toDate = $to->copy()->timezone('America/New_York')->toDateString();

        if ($fromDate > $toDate) {
            return [];
        }

        $ticker = $this->benchmarkTicker();
        $rangeKey = "vestix:benchmark-closes:{$ticker}:{$fromDate}:{$toDate}";
        Cache::forget($rangeKey);
        Cache::forget("{$rangeKey}:miss");

        $closes = $this->fetchClosesBetween($ticker, $fromDate, $toDate);

        if ($closes === []) {
            return [];
        }

        Cache::put($rangeKey, $closes, now()->addHours(12));

        foreach ($closes as $date => $close) {
            Cache::put("vestix:benchmark-close:{$ticker}:{$date}", $close, now()->addDay());
        }

        return $closes;
    }

    private function shouldFetchRemoteCloses(string $rangeKey): bool
    {
        if (Cache::has("{$rangeKey}:miss")) {
            return false;
        }

        // PHPUnit + artisan + queues may warm densify data; HTTP/Livewire must stay fast.
        return app()->runningInConsole();
    }

    /**
     * @return array<string, float>
     */
    private function cachedClosesInRange(string $ticker, string $fromDate, string $toDate): array
    {
        $closes = [];
        $cursor = Carbon::parse($fromDate, 'America/New_York')->startOfDay();
        $end = Carbon::parse($toDate, 'America/New_York')->startOfDay();

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $date = $cursor->toDateString();
                $cached = Cache::get("vestix:benchmark-close:{$ticker}:{$date}");

                if (is_float($cached) || is_int($cached)) {
                    $closes[$date] = (float) $cached;
                }
            }

            $cursor->addDay();
        }

        return $closes;
    }

    /**
     * @return array<string, float>
     */
    private function fetchClosesBetween(string $ticker, string $fromDate, string $toDate): array
    {
        $lookbackDays = max(14, (int) Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) + 10);
        $limit = max(50, $lookbackDays + 5);
        $barsPayload = $this->dailyBars->fetchRecentBars($ticker, $lookbackDays, $limit);

        if ($barsPayload === null) {
            return [];
        }

        $closes = [];

        foreach ($barsPayload['bars'] as $bar) {
            $date = $bar['date'];

            if ($date < $fromDate || $date > $toDate) {
                continue;
            }

            $closes[$date] = (float) $bar['close'];
        }

        ksort($closes);

        return $closes;
    }
}
