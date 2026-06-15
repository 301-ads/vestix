<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use Illuminate\Support\Facades\Log;

class FallbackDailyBarProvider implements DailyBarProvider
{
    public function __construct(
        private PolygonDailyBarService $polygon,
        private FinnhubDailyBarService $finnhub,
        private AlphaVantageDailyBarService $alphaVantage,
    ) {}

    public function fetchRecentBars(string $ticker, int $lookbackDays = 31, int $limit = 50): ?array
    {
        $bars = $this->polygon->fetchRecentBars($ticker, $lookbackDays, $limit);

        if ($bars !== null) {
            return $bars;
        }

        Log::info('Polygon daily bars unavailable — falling back to Finnhub.', [
            'ticker' => $ticker,
        ]);

        $bars = $this->finnhub->fetchRecentBars($ticker, $lookbackDays, $limit);

        if ($bars !== null) {
            return $bars;
        }

        Log::info('Finnhub daily bars unavailable — falling back to Alpha Vantage.', [
            'ticker' => $ticker,
        ]);

        return $this->alphaVantage->fetchRecentBars($ticker, $lookbackDays, $limit);
    }
}
