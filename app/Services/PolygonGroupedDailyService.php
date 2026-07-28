<?php

namespace App\Services;

use App\Support\PolygonRateLimiter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonGroupedDailyService
{
    public function __construct(
        private readonly PolygonRateLimiter $rateLimiter,
    ) {}

    /**
     * @return list<array{ticker: string, open: float, high: float, low: float, close: float, volume: int}>|null
     */
    public function fetchForDate(string $date): ?array
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            Log::warning('Polygon API key not configured for grouped daily.');

            return null;
        }

        $baseUrl = rtrim((string) config('vestix.polygon.base_url'), '/');
        $url = "{$baseUrl}/v2/aggs/grouped/locale/us/market/stocks/{$date}";

        try {
            $response = $this->request($url, $apiKey);

            if ($response === null) {
                return null;
            }

            $data = $response->json();

            if (! isset($data['results']) || ! is_array($data['results'])) {
                Log::warning('Polygon grouped daily response invalid.', [
                    'date' => $date,
                    'status' => $data['status'] ?? null,
                ]);

                return null;
            }

            $rows = [];

            foreach ($data['results'] as $bar) {
                if (! is_array($bar) || ! isset($bar['T'], $bar['o'], $bar['h'], $bar['l'], $bar['c'], $bar['v'])) {
                    continue;
                }

                $ticker = strtoupper(trim((string) $bar['T']));

                if ($ticker === '' || str_contains($ticker, '.')) {
                    // Skip preferreds / weird suffixes in v1 (e.g. BRK.B still has dot — allow BRK.B later if needed)
                    if (! preg_match('/^[A-Z]{1,5}(\.[A-Z])?$/', $ticker)) {
                        continue;
                    }
                }

                $rows[] = [
                    'ticker' => $ticker,
                    'open' => (float) $bar['o'],
                    'high' => (float) $bar['h'],
                    'low' => (float) $bar['l'],
                    'close' => (float) $bar['c'],
                    'volume' => (int) round((float) $bar['v']),
                ];
            }

            return $rows;
        } catch (\Throwable $exception) {
            Log::error('Polygon grouped daily exception.', [
                'date' => $date,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array{execution_date: string, split_from: float, split_to: float}> 
     */
    public function fetchSplits(string $ticker, string $from, string $to): array
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            return [];
        }

        $baseUrl = rtrim((string) config('vestix.polygon.base_url'), '/');
        $url = "{$baseUrl}/v3/reference/splits";

        try {
            $this->rateLimiter->waitBeforeRequest();

            $response = Http::timeout(30)->get($url, [
                'ticker' => strtoupper(trim($ticker)),
                'execution_date.gte' => $from,
                'execution_date.lte' => $to,
                'limit' => 10,
                'apiKey' => $apiKey,
            ]);

            if ($response->status() === 429) {
                $this->rateLimiter->waitAfterRateLimitResponse();

                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            $results = $response->json('results');

            if (! is_array($results)) {
                return [];
            }

            $splits = [];

            foreach ($results as $row) {
                if (! is_array($row) || ! isset($row['execution_date'])) {
                    continue;
                }

                $splits[] = [
                    'execution_date' => (string) $row['execution_date'],
                    'split_from' => (float) ($row['split_from'] ?? 1),
                    'split_to' => (float) ($row['split_to'] ?? 1),
                ];
            }

            return $splits;
        } catch (\Throwable) {
            return [];
        }
    }

    private function request(string $url, string $apiKey): ?Response
    {
        $this->rateLimiter->waitBeforeRequest();

        $response = Http::timeout(120)->get($url, [
            'adjusted' => 'true',
            'apiKey' => $apiKey,
        ]);

        if ($response->status() === 429) {
            $this->rateLimiter->waitAfterRateLimitResponse();
            $this->rateLimiter->waitBeforeRequest();

            $response = Http::timeout(120)->get($url, [
                'adjusted' => 'true',
                'apiKey' => $apiKey,
            ]);
        }

        if (! $response->successful()) {
            Log::warning('Polygon grouped daily request failed.', [
                'status' => $response->status(),
                'url' => $url,
            ]);

            return null;
        }

        return $response;
    }
}
