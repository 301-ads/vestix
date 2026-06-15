<?php

namespace App\Services;

use App\Contracts\QuoteProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonQuoteProvider implements QuoteProvider
{
    public function fetchLivePrice(string $ticker): ?float
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            Log::warning('Polygon API key not configured.');

            return null;
        }

        $baseUrl = rtrim(config('vestix.polygon.base_url'), '/');

        try {
            $response = Http::timeout(30)->get("{$baseUrl}/v2/last/trade/{$ticker}", [
                'apiKey' => $apiKey,
            ]);

            if (! $response->successful()) {
                Log::warning('Polygon request failed.', [
                    'status' => $response->status(),
                    'ticker' => $ticker,
                    'message' => $response->json('message'),
                ]);

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
}
