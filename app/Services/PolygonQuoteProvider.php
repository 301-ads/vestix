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
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            Log::warning('Polygon API key not configured.');

            return null;
        }

        $baseUrl = rtrim(config('vestix.polygon.base_url'), '/');

        try {
            $response = $this->requestLastTrade($baseUrl, $ticker, $apiKey);

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

    public function fetchSessionQuote(string $ticker): ?array
    {
        $price = $this->fetchLivePrice($ticker);

        if ($price === null) {
            return null;
        }

        return [
            'close' => $price,
            'high' => null,
            'low' => null,
        ];
    }

    /**
     * @return \Illuminate\Http\Client\Response|null
     */
    private function requestLastTrade(string $baseUrl, string $ticker, string $apiKey): ?\Illuminate\Http\Client\Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->rateLimiter->waitBeforeRequest();

            $response = Http::timeout(30)->get("{$baseUrl}/v2/last/trade/{$ticker}", [
                'apiKey' => $apiKey,
            ]);

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
