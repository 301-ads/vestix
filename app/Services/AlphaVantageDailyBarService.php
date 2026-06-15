<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use Illuminate\Support\Facades\Log;

class AlphaVantageDailyBarService implements DailyBarProvider
{
    public function __construct(private AlphaVantageService $alphaVantage) {}

    public function fetchRecentBars(string $ticker, int $lookbackDays = 31, int $limit = 50): ?array
    {
        $data = $this->alphaVantage->fetchDailyTimeSeries($ticker);

        if ($data === null) {
            return null;
        }

        $bars = collect($data)
            ->map(fn (array $bar, string $date): array => [
                'open' => (float) ($bar['1. open'] ?? 0),
                'high' => (float) ($bar['2. high'] ?? 0),
                'low' => (float) ($bar['3. low'] ?? 0),
                'close' => (float) ($bar['4. close'] ?? 0),
                'volume' => (float) ($bar['6. volume'] ?? 0),
                'date' => $date,
            ])
            ->sortBy('date')
            ->values()
            ->all();

        if ($limit > 0) {
            $bars = array_slice($bars, -$limit);
        }

        if (count($bars) < 2) {
            Log::warning('Alpha Vantage daily bars insufficient data.', [
                'ticker' => $ticker,
                'count' => count($bars),
            ]);

            return null;
        }

        $today = $bars[array_key_last($bars)];
        $priorBars = array_slice($bars, 0, -1);
        $advBars = array_slice($priorBars, -30);

        if ($advBars === []) {
            return null;
        }

        $adv30 = array_sum(array_column($advBars, 'volume')) / count($advBars);

        return [
            'today' => [
                'open' => $today['open'],
                'high' => $today['high'],
                'low' => $today['low'],
                'close' => $today['close'],
                'volume' => $today['volume'],
            ],
            'adv30' => $adv30,
            'bars' => $bars,
        ];
    }
}
