<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PremarketQuoteCapability
{
    private const CACHE_KEY = 'vestix.premarket_quote_capability';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return array{
     *     polygon_realtime: bool,
     *     finnhub_intraday: bool,
     *     message: string,
     * }
     */
    public static function assess(string $probeTicker = 'AMD'): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($probeTicker): array {
            $polygonRealtime = self::polygonRealtimeAvailable($probeTicker);
            $finnhubIntraday = self::finnhubIntradayAvailable($probeTicker);

            $message = match (true) {
                $polygonRealtime => 'Polygon realtime beschikbaar voor pre-market quotes.',
                $finnhubIntraday => 'Finnhub intraday candles beschikbaar voor pre-market quotes.',
                default => 'Geen live pre-market bron beschikbaar op het huidige API-plan. '
                    .'Polygon realtime/snapshot en Finnhub 1-min candles geven 403. '
                    .'Finnhub/Alpha Vantage /quote levert alleen de laatste slotkoers.',
            };

            return [
                'polygon_realtime' => $polygonRealtime,
                'finnhub_intraday' => $finnhubIntraday,
                'message' => $message,
            ];
        });
    }

    public static function hasLivePremarketSource(): bool
    {
        $assessment = self::assess();

        return $assessment['polygon_realtime'] || $assessment['finnhub_intraday'];
    }

    public static function forgetCachedAssessment(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function polygonRealtimeAvailable(string $ticker): bool
    {
        $apiKey = config('vestix.polygon.api_key');

        if (! $apiKey) {
            return false;
        }

        $baseUrl = rtrim((string) config('vestix.polygon.base_url'), '/');

        try {
            $response = Http::timeout(15)->get("{$baseUrl}/v2/snapshot/locale/us/markets/stocks/tickers/{$ticker}", [
                'apiKey' => $apiKey,
            ]);

            if ($response->status() === 403) {
                Log::info('Polygon realtime snapshot not entitled on current plan.', [
                    'ticker' => $ticker,
                    'message' => $response->json('message'),
                ]);

                return false;
            }

            return $response->successful()
                && is_array($response->json('ticker.lastTrade'))
                && isset($response->json('ticker.lastTrade')['p']);
        } catch (\Throwable $exception) {
            Log::warning('Polygon realtime capability probe failed.', [
                'ticker' => $ticker,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private static function finnhubIntradayAvailable(string $ticker): bool
    {
        $apiKey = config('vestix.finnhub.api_key');

        if (! $apiKey) {
            return false;
        }

        $baseUrl = rtrim((string) config('vestix.finnhub.base_url'), '/');
        $now = now('America/New_York');
        $from = $now->copy()->startOfDay()->timestamp;

        try {
            $response = Http::timeout(15)->get("{$baseUrl}/stock/candle", [
                'symbol' => $ticker,
                'resolution' => '1',
                'from' => $from,
                'to' => $now->timestamp,
                'token' => $apiKey,
            ]);

            if ($response->status() === 403) {
                Log::info('Finnhub intraday candles not entitled on current plan.', [
                    'ticker' => $ticker,
                ]);

                return false;
            }

            return $response->successful() && ($response->json('s') ?? null) === 'ok';
        } catch (\Throwable $exception) {
            Log::warning('Finnhub intraday capability probe failed.', [
                'ticker' => $ticker,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
