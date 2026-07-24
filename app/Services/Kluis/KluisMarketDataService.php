<?php

namespace App\Services\Kluis;

use App\Contracts\DailyBarProvider;
use App\Models\VaultSetting;
use App\Support\Kluis\KluisThermometerReading;
use App\Support\TechnicalIndicators;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KluisMarketDataService
{
    public function __construct(
        private DailyBarProvider $dailyBars,
        private KluisThermometer $thermometer,
    ) {}

    public function fetchReading(VaultSetting $settings, bool $force = false): ?KluisThermometerReading
    {
        $ticker = strtoupper(trim((string) $settings->etf_ticker));
        $cacheKey = "vestix:kluis:thermometer:{$ticker}";
        $ttl = max(60, (int) config('vestix.kluis.cache_ttl_seconds', 3600));

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && isset($cached['close'], $cached['sma_200'])) {
            return $this->thermometer->readingFromPrices(
                (float) $cached['close'],
                (float) $cached['sma_200'],
                $ticker,
                $settings,
                $cached['resolved_symbol'] ?? null,
            );
        }

        // Avoid blocking page loads; only hit providers when explicitly refreshed.
        if (! $force) {
            return null;
        }

        $payload = $this->fetchBarsPayload($ticker);

        if ($payload === null) {
            return null;
        }

        Cache::put($cacheKey, $payload, now()->addSeconds($ttl));

        return $this->thermometer->readingFromPrices(
            $payload['close'],
            $payload['sma_200'],
            $ticker,
            $settings,
            $payload['resolved_symbol'],
        );
    }

    /**
     * @return array{close: float, sma_200: float, resolved_symbol: string}|null
     */
    public function fetchBarsPayload(string $displayTicker): ?array
    {
        $lookbackDays = max(220, (int) config('vestix.kluis.bar_lookback_days', 320));
        $limit = max(200, (int) config('vestix.kluis.bar_limit', 250));

        foreach ($this->candidateSymbols($displayTicker) as $symbol) {
            $bars = $this->dailyBars->fetchRecentBars($symbol, $lookbackDays, $limit);

            if ($bars === null || count($bars['bars']) < 200) {
                Log::info('Kluis SMA-200 bars insufficient.', [
                    'display_ticker' => $displayTicker,
                    'symbol' => $symbol,
                    'bar_count' => $bars === null ? null : count($bars['bars']),
                ]);

                continue;
            }

            $closes = array_map(
                static fn (array $bar): float => (float) $bar['close'],
                $bars['bars'],
            );

            $sma200 = TechnicalIndicators::sma($closes, 200);

            if ($sma200 === null) {
                continue;
            }

            $close = (float) end($closes);

            return [
                'close' => $close,
                'sma_200' => $sma200,
                'resolved_symbol' => $symbol,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function candidateSymbols(string $displayTicker): array
    {
        $displayTicker = strtoupper(trim($displayTicker));
        $proxy = config("vestix.kluis.thermometer_proxies.{$displayTicker}");
        $polygon = config("vestix.kluis.polygon_tickers.{$displayTicker}");
        $finnhub = config("vestix.kluis.finnhub_symbols.{$displayTicker}");

        // Prefer working US proxies first — EU symbols often unavailable on free API tiers
        // and cause long timeouts before fallbacks.
        return array_values(array_unique(array_filter([
            is_string($proxy) && $proxy !== '' ? strtoupper($proxy) : null,
            is_string($polygon) && $polygon !== '' ? strtoupper($polygon) : null,
            $displayTicker,
            is_string($finnhub) && $finnhub !== '' ? strtoupper($finnhub) : null,
        ])));
    }
}
