<?php

namespace App\Services\Kluis;

use App\Contracts\DailyBarProvider;
use App\Contracts\QuoteProvider;
use App\Models\VaultSetting;
use App\Support\Kluis\KluisThermometerReading;
use App\Support\TechnicalIndicators;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KluisMarketDataService
{
    public function __construct(
        private DailyBarProvider $dailyBars,
        private QuoteProvider $quotes,
        private KluisThermometer $thermometer,
    ) {}

    public function fetchReading(VaultSetting $settings, bool $force = false): ?KluisThermometerReading
    {
        $ticker = strtoupper(trim((string) $settings->etf_ticker));
        $cacheKey = "vestix:kluis:thermometer:{$ticker}";
        $ttl = max(60, (int) config('vestix.kluis.cache_ttl_seconds', 3600));

        // Avoid blocking page loads; only hit providers when explicitly refreshed.
        // When forced, skip cache so "Thermometer verversen" always refetches.
        if (! $force) {
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
     * EUR broker valuation price for holdings MTM — never the USD thermometer proxy.
     *
     * @return array{price: float, resolved_symbol: string}|null
     */
    public function fetchHoldingsPrice(string $displayTicker, bool $force = false): ?array
    {
        $displayTicker = strtoupper(trim($displayTicker));
        $cacheKey = "vestix:kluis:holdings-price:{$displayTicker}";
        $ttl = max(60, (int) config('vestix.kluis.cache_ttl_seconds', 93600));

        if (! $force) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && isset($cached['price']) && (float) $cached['price'] > 0) {
                return [
                    'price' => (float) $cached['price'],
                    'resolved_symbol' => (string) ($cached['resolved_symbol'] ?? $displayTicker),
                ];
            }

            return null;
        }

        foreach ($this->holdingsPriceSymbols($displayTicker) as $symbol) {
            $price = $this->quotes->fetchLivePrice($symbol);

            if ($price === null || $price <= 0) {
                Log::info('Kluis holdings quote unavailable.', [
                    'display_ticker' => $displayTicker,
                    'symbol' => $symbol,
                ]);

                continue;
            }

            $payload = [
                'price' => round((float) $price, 4),
                'resolved_symbol' => $symbol,
            ];

            Cache::put($cacheKey, $payload, now()->addSeconds($ttl));

            return $payload;
        }

        return null;
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

    /**
     * Symbols for EUR holdings valuation (excludes thermometer USD proxies).
     *
     * @return list<string>
     */
    public function holdingsPriceSymbols(string $displayTicker): array
    {
        $displayTicker = strtoupper(trim($displayTicker));
        $configured = config("vestix.kluis.holdings_price_symbols.{$displayTicker}");
        $finnhub = config("vestix.kluis.finnhub_symbols.{$displayTicker}");
        $proxy = config("vestix.kluis.thermometer_proxies.{$displayTicker}");
        $proxy = is_string($proxy) && $proxy !== '' ? strtoupper($proxy) : null;

        $candidates = is_array($configured) && $configured !== []
            ? $configured
            : array_filter([
                is_string($finnhub) && $finnhub !== '' ? $finnhub : null,
                $displayTicker,
            ]);

        $symbols = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $symbol = strtoupper(trim($candidate));

            if ($proxy !== null && $symbol === $proxy) {
                continue;
            }

            $symbols[] = $symbol;
        }

        return array_values(array_unique($symbols));
    }
}
