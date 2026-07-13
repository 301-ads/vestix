<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
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
        $dateString = $date->toDateString();

        return Cache::remember(
            "vestix:benchmark-close:{$ticker}:{$dateString}",
            now()->addDay(),
            function () use ($ticker, $date): ?float {
                $lookbackDays = max(14, $date->diffInDays(now()) + 10);
                $barsPayload = $this->dailyBars->fetchRecentBars($ticker, $lookbackDays, 120);

                if ($barsPayload === null) {
                    return null;
                }

                $targetDate = $date->copy()->timezone('America/New_York')->toDateString();
                $bars = $barsPayload['bars'];
                $closeOnOrBefore = null;

                foreach ($bars as $bar) {
                    if ($bar['date'] <= $targetDate) {
                        $closeOnOrBefore = (float) $bar['close'];
                    }
                }

                return $closeOnOrBefore;
            },
        );
    }

    /**
     * Last US trading day on or before the given calendar date (for weekend snapshots).
     */
    public function resolveTradingDayClose(Carbon $date): ?float
    {
        $cursor = $date->copy()->timezone('America/New_York')->startOfDay();

        while ($cursor->isWeekend()) {
            $cursor->subDay();
        }

        return $this->resolveCloseForDate($cursor);
    }
}
