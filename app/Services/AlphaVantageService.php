<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlphaVantageService
{
    public function fetchQuote(string $ticker): ?float
    {
        $data = $this->request([
            'function' => 'GLOBAL_QUOTE',
            'symbol' => $ticker,
        ]);

        if (! $data || ! isset($data['Global Quote']['05. price'])) {
            return null;
        }

        return (float) $data['Global Quote']['05. price'];
    }

    public function fetchSma20(string $ticker): ?float
    {
        return $this->fetchSma20Pair($ticker)['latest'] ?? null;
    }

    /**
     * @return array{latest: float|null, five_days_ago: float|null}
     */
    public function fetchSma20Pair(string $ticker): array
    {
        $data = $this->request([
            'function' => 'SMA',
            'symbol' => $ticker,
            'interval' => 'daily',
            'time_period' => 20,
            'series_type' => 'close',
        ]);

        return [
            'latest' => $this->indicatorSeriesValueAtOffset($data, 'Technical Analysis: SMA', 'SMA', 0),
            'five_days_ago' => $this->indicatorSeriesValueAtOffset($data, 'Technical Analysis: SMA', 'SMA', 5),
        ];
    }

    public function fetchSma50(string $ticker): ?float
    {
        $data = $this->request([
            'function' => 'SMA',
            'symbol' => $ticker,
            'interval' => 'daily',
            'time_period' => 50,
            'series_type' => 'close',
        ]);

        return $this->latestIndicatorValue($data, 'Technical Analysis: SMA', 'SMA');
    }

    public function fetchRsi14(string $ticker): ?float
    {
        $data = $this->request([
            'function' => 'RSI',
            'symbol' => $ticker,
            'interval' => 'daily',
            'time_period' => 14,
            'series_type' => 'close',
        ]);

        return $this->latestIndicatorValue($data, 'Technical Analysis: RSI', 'RSI');
    }

    public function fetchAtr14(string $ticker): ?float
    {
        $data = $this->request([
            'function' => 'ATR',
            'symbol' => $ticker,
            'interval' => 'daily',
            'time_period' => 14,
        ]);

        return $this->latestIndicatorValue($data, 'Technical Analysis: ATR', 'ATR');
    }

    private function request(array $params): ?array
    {
        $apiKey = config('swng.alpha_vantage.api_key');

        if (! $apiKey) {
            Log::warning('Alpha Vantage API key not configured.');

            return null;
        }

        try {
            $response = Http::timeout(30)->get(config('swng.alpha_vantage.base_url'), [
                ...$params,
                'apikey' => $apiKey,
            ]);

            if (! $response->successful()) {
                Log::warning('Alpha Vantage request failed.', [
                    'status' => $response->status(),
                    'params' => $params,
                ]);

                return null;
            }

            $data = $response->json();

            if (isset($data['Note']) || isset($data['Information'])) {
                Log::warning('Alpha Vantage rate limit or info message.', [
                    'message' => $data['Note'] ?? $data['Information'],
                    'params' => $params,
                ]);

                return null;
            }

            return $data;
        } catch (\Throwable $exception) {
            Log::error('Alpha Vantage request exception.', [
                'message' => $exception->getMessage(),
                'params' => $params,
            ]);

            return null;
        }
    }

    private function latestIndicatorValue(?array $data, string $section, string $valueKey): ?float
    {
        return $this->indicatorSeriesValueAtOffset($data, $section, $valueKey, 0);
    }

    private function indicatorSeriesValueAtOffset(?array $data, string $section, string $valueKey, int $offset): ?float
    {
        if (! $data || ! isset($data[$section]) || ! is_array($data[$section])) {
            return null;
        }

        $entry = collect($data[$section])
            ->filter(fn ($value, $key) => $key !== 'Meta Data' && is_array($value))
            ->sortKeysDesc()
            ->values()
            ->get($offset);

        if (! is_array($entry) || ! isset($entry[$valueKey])) {
            return null;
        }

        return (float) $entry[$valueKey];
    }
}
