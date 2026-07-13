<?php

namespace App\Services;

use App\Contracts\QuoteProvider;
use App\Support\PolygonRateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonQuoteProvider implements QuoteProvider
{
    public function __construct(
        private readonly PolygonRateLimiter $rateLimiter,
    ) {}

    public function fetchLivePrice(string $ticker): ?float
    {
        return $this->fetchSnapshotLastTradePrice($ticker)
            ?? $this->fetchLastTradePrice($ticker);
    }

    public function fetchPremarketPrice(string $ticker, ?float $referenceClose = null): ?float
    {
        return $this->fetchLivePrice($ticker);
    }

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null, previous_close: float|null}|null
     */
    public function fetchSessionQuote(string $ticker): ?array
    {
        $snapshot = $this->fetchSnapshot($ticker);

        if ($snapshot !== null) {
            return $snapshot;
        }

        $price = $this->fetchLastTradePrice($ticker);

        if ($price === null) {
            return null;
        }

        return [
            'open' => null,
            'close' => $price,
            'high' => null,
            'low' => null,
            'previous_close' => null,
        ];
    }

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null, previous_close: float|null}|null
     */
    private function fetchSnapshot(string $ticker): ?array
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            return null;
        }

        $baseUrl = rtrim(config('vestix.polygon.base_url'), '/');

        try {
            $response = $this->request("{$baseUrl}/v2/snapshot/locale/us/markets/stocks/tickers/{$ticker}", [
                'apiKey' => $apiKey,
            ], $ticker);

            if ($response === null) {
                return null;
            }

            $data = $response->json();
            $tickerData = $data['ticker'] ?? null;

            if (! is_array($tickerData)) {
                return null;
            }

            $lastTrade = $tickerData['lastTrade']['p'] ?? null;
            $previousClose = $tickerData['prevDay']['c'] ?? null;

            if ($lastTrade === null) {
                return null;
            }

            $day = $tickerData['day'] ?? null;

            return [
                'open' => isset($day['o']) && (float) $day['o'] > 0 ? (float) $day['o'] : null,
                'close' => (float) $lastTrade,
                'high' => isset($day['h']) && (float) $day['h'] > 0 ? (float) $day['h'] : null,
                'low' => isset($day['l']) && (float) $day['l'] > 0 ? (float) $day['l'] : null,
                'previous_close' => $previousClose !== null && (float) $previousClose > 0
                    ? (float) $previousClose
                    : null,
            ];
        } catch (\Throwable $exception) {
            Log::error('Polygon snapshot request exception.', [
                'message' => $exception->getMessage(),
                'ticker' => $ticker,
            ]);

            return null;
        }
    }

    private function fetchSnapshotLastTradePrice(string $ticker): ?float
    {
        return $this->fetchSnapshot($ticker)['close'] ?? null;
    }

    private function fetchLastTradePrice(string $ticker): ?float
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            Log::warning('Polygon API key not configured.');

            return null;
        }

        $baseUrl = rtrim(config('vestix.polygon.base_url'), '/');

        try {
            $response = $this->request("{$baseUrl}/v2/last/trade/{$ticker}", [
                'apiKey' => $apiKey,
            ], $ticker);

            if ($response === null) {
                return null;
            }

            $data = $response->json();

            if (isset($data['status']) && $data['status'] === 'ERROR') {
                Log::warning('Polygon API error.', [
                    'message' => $data['error'] ?? 'Unknown error',
                    'ticker' => $ticker,
                ]);

                return null;
            }

            if (! isset($data['results']['p'])) {
                Log::warning('Polygon response missing price.', [
                    'ticker' => $ticker,
                ]);

                return null;
            }

            return (float) $data['results']['p'];
        } catch (\Throwable $exception) {
            Log::error('Polygon request exception.', [
                'message' => $exception->getMessage(),
                'ticker' => $ticker,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(string $url, array $query, string $ticker): ?\Illuminate\Http\Client\Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->rateLimiter->waitBeforeRequest();

            $response = Http::timeout(30)->get($url, $query);

            if ($response->status() === 429 && $attempt === 0) {
                Log::warning('Polygon quote rate limited — retrying after delay.', [
                    'ticker' => $ticker,
                ]);
                $this->rateLimiter->waitAfterRateLimitResponse();

                continue;
            }

            if (! $response->successful()) {
                Log::warning('Polygon request failed.', [
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
