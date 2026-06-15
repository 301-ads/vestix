<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonReferenceService
{
    /**
     * @return array{name: string|null, icon_url: string|null, logo_url: string|null}|null
     */
    public function fetchTickerBranding(string $ticker): ?array
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            Log::warning('Polygon API key not configured.');

            return null;
        }

        $baseUrl = rtrim(config('vestix.polygon.base_url'), '/');
        $ticker = strtoupper(trim($ticker));

        try {
            $response = Http::timeout(30)->get("{$baseUrl}/v3/reference/tickers/{$ticker}", [
                'apiKey' => $apiKey,
            ]);

            if (! $response->successful()) {
                Log::warning('Polygon ticker reference request failed.', [
                    'status' => $response->status(),
                    'ticker' => $ticker,
                    'message' => $response->json('message'),
                ]);

                return null;
            }

            $data = $response->json();

            if (isset($data['status']) && $data['status'] === 'ERROR') {
                Log::warning('Polygon ticker reference API error.', [
                    'message' => $data['error'] ?? 'Unknown error',
                    'ticker' => $ticker,
                ]);

                return null;
            }

            $results = $data['results'] ?? null;

            if (! is_array($results)) {
                Log::warning('Polygon ticker reference response missing results.', [
                    'ticker' => $ticker,
                ]);

                return null;
            }

            $branding = $results['branding'] ?? [];

            return [
                'name' => $results['name'] ?? null,
                'icon_url' => $branding['icon_url'] ?? null,
                'logo_url' => $branding['logo_url'] ?? null,
            ];
        } catch (\Throwable $exception) {
            Log::error('Polygon ticker reference request exception.', [
                'message' => $exception->getMessage(),
                'ticker' => $ticker,
            ]);

            return null;
        }
    }
}
