<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Support\PolygonRateLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonDailyBarService implements DailyBarProvider
{
    public function __construct(
        private readonly PolygonRateLimiter $rateLimiter,
    ) {}
    /**
     * @return array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }|null
     */
    public function fetchRecentBars(string $ticker, int $lookbackDays = 31, int $limit = 50): ?array
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            Log::warning('Polygon API key not configured for daily bars.');

            return null;
        }

        $to = Carbon::today('America/New_York');
        $from = $to->copy()->subDays($lookbackDays + 5);
        $baseUrl = rtrim(config('vestix.polygon.base_url'), '/');

        try {
            $response = $this->requestDailyBars(
                $baseUrl,
                $ticker,
                $from,
                $to,
                $apiKey,
                $limit,
            );

            if ($response === null) {
                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? null) === 'ERROR' || ! isset($data['results']) || ! is_array($data['results'])) {
                Log::warning('Polygon daily bars response invalid.', [
                    'ticker' => $ticker,
                    'message' => $data['error'] ?? 'Missing results',
                ]);

                return null;
            }

            $bars = collect($data['results'])
                ->map(fn (array $bar): array => [
                    'open' => (float) $bar['o'],
                    'high' => (float) $bar['h'],
                    'low' => (float) $bar['l'],
                    'close' => (float) $bar['c'],
                    'volume' => (float) $bar['v'],
                    'date' => Carbon::createFromTimestampMs((int) $bar['t'])->timezone('America/New_York')->toDateString(),
                ])
                ->values()
                ->all();

            if (count($bars) < 2) {
                Log::warning('Polygon daily bars insufficient data.', [
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
        } catch (\Throwable $exception) {
            Log::error('Polygon daily bars exception.', [
                'message' => $exception->getMessage(),
                'ticker' => $ticker,
            ]);

            return null;
        }
    }

    public static function isBounceDay(float $low, float $close, float $sma20): bool
    {
        return $low < $sma20 && $close > $sma20;
    }

    /**
     * @return \Illuminate\Http\Client\Response|null
     */
    private function requestDailyBars(
        string $baseUrl,
        string $ticker,
        Carbon $from,
        Carbon $to,
        string $apiKey,
        int $limit,
    ): ?\Illuminate\Http\Client\Response {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->rateLimiter->waitBeforeRequest();

            $response = Http::timeout(30)->get(
                "{$baseUrl}/v2/aggs/ticker/{$ticker}/range/1/day/{$from->format('Y-m-d')}/{$to->format('Y-m-d')}",
                [
                    'apiKey' => $apiKey,
                    'adjusted' => 'true',
                    'sort' => 'asc',
                    'limit' => $limit,
                ],
            );

            if ($response->status() === 429 && $attempt === 0) {
                Log::warning('Polygon daily bars rate limited — retrying after delay.', [
                    'ticker' => $ticker,
                ]);
                $this->rateLimiter->waitAfterRateLimitResponse();

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Polygon daily bars request failed.', [
                    'status' => $response->status(),
                    'ticker' => $ticker,
                    'message' => $response->json('message'),
                ]);

                return null;
            }

            return $response;
        }

        return null;
    }
}
