<?php

namespace App\Services;

use App\Contracts\QuoteProvider;
use App\Support\PremarketQuoteCapability;
use App\Support\UsMarketSession;
use Illuminate\Support\Facades\Log;

class FallbackQuoteProvider implements QuoteProvider
{
    public function __construct(
        private FinnhubQuoteProvider $finnhub,
        private AlphaVantageQuoteProvider $alphaVantage,
        private PolygonQuoteProvider $polygon,
        private TradingViewPremarketQuoteService $tradingViewPremarket,
    ) {}

    public function fetchLivePrice(string $ticker): ?float
    {
        $quote = $this->fetchSessionQuoteWithProvider($ticker);

        return $quote['close'] ?? null;
    }

    public function fetchPremarketPrice(string $ticker, ?float $referenceClose = null): ?float
    {
        $assessment = PremarketQuoteCapability::assess($ticker);

        if ($assessment['finnhub_intraday']) {
            $intradayPrice = $this->finnhub->fetchIntradayClose($ticker);

            if ($intradayPrice !== null && ! $this->isStalePremarketQuote($intradayPrice, [], $referenceClose)) {
                return $intradayPrice;
            }
        }

        if ($assessment['polygon_realtime']) {
            $quote = $this->polygon->fetchSessionQuote($ticker);

            if ($quote !== null && isset($quote['close'])) {
                $price = (float) $quote['close'];

                if (! $this->isStalePremarketQuote($price, $quote, $referenceClose)) {
                    return $price;
                }

                Log::info('Pre-market quote rejected as stale close — trying next fallback.', [
                    'ticker' => $ticker,
                    'provider' => 'polygon',
                    'price' => $price,
                    'reference_close' => $referenceClose,
                    'provider_previous_close' => $quote['previous_close'] ?? null,
                ]);
            }
        }

        // Prefer TradingView premarket_close over Finnhub/AV /quote (those are usually RTH closes).
        if ($assessment['tradingview_scanner'] ?? true) {
            $tv = $this->tradingViewPremarket->fetchPremarketQuote($ticker);

            if ($tv['ok']) {
                $tvPrice = $tv['price'];

                if ($tvPrice !== null && $tvPrice > 0 && ! $this->isStalePremarketQuote($tvPrice, [], $referenceClose)) {
                    return $tvPrice;
                }

                if ($tvPrice !== null && $this->isStalePremarketQuote($tvPrice, [], $referenceClose)) {
                    Log::info('TradingView premarket rejected as stale close.', [
                        'ticker' => $ticker,
                        'price' => $tvPrice,
                        'reference_close' => $referenceClose,
                    ]);
                }

                // Listing resolved: null price means no EH trades — do not fall back to RTH /quote.
                return null;
            }
        }

        foreach ($this->premarketQuoteFallbacks() as $entry) {
            $quote = $entry['provider']->fetchSessionQuote($ticker);

            if ($quote === null || ! isset($quote['close'])) {
                Log::info('Pre-market quote provider unavailable — trying next fallback.', [
                    'ticker' => $ticker,
                    'provider' => $entry['name'],
                ]);

                continue;
            }

            $price = (float) $quote['close'];

            if ($this->isStalePremarketQuote($price, $quote, $referenceClose)) {
                Log::info('Pre-market quote rejected as stale close — trying next fallback.', [
                    'ticker' => $ticker,
                    'provider' => $entry['name'],
                    'price' => $price,
                    'reference_close' => $referenceClose,
                    'provider_previous_close' => $quote['previous_close'] ?? null,
                ]);

                continue;
            }

            return $price;
        }

        return null;
    }

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null, provider?: string}|null
     */
    public function fetchSessionQuote(string $ticker): ?array
    {
        $quote = $this->fetchSessionQuoteWithProvider($ticker);

        if ($quote === null) {
            return null;
        }

        return [
            'open' => $quote['open'] ?? null,
            'close' => $quote['close'],
            'high' => $quote['high'] ?? null,
            'low' => $quote['low'] ?? null,
            'provider' => $quote['provider'],
        ];
    }

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null, provider: string}|null
     */
    public function fetchSessionQuoteWithProvider(string $ticker): ?array
    {
        foreach ($this->regularProviders() as $entry) {
            $quote = $entry['provider']->fetchSessionQuote($ticker);

            if ($quote !== null && isset($quote['close'])) {
                return [
                    'open' => $quote['open'] ?? null,
                    'close' => $quote['close'],
                    'high' => $quote['high'] ?? null,
                    'low' => $quote['low'] ?? null,
                    'provider' => $entry['name'],
                ];
            }

            Log::info('Quote provider unavailable — trying next fallback.', [
                'ticker' => $ticker,
                'provider' => $entry['name'],
            ]);
        }

        return null;
    }

    /**
     * @return list<array{name: string, provider: QuoteProvider}>
     */
    private function regularProviders(): array
    {
        return [
            ['name' => 'finnhub', 'provider' => $this->finnhub],
            ['name' => 'alpha_vantage', 'provider' => $this->alphaVantage],
            ['name' => 'polygon', 'provider' => $this->polygon],
        ];
    }

    /**
     * @return list<array{name: string, provider: QuoteProvider}>
     */
    private function premarketQuoteFallbacks(): array
    {
        return [
            ['name' => 'finnhub', 'provider' => $this->finnhub],
            ['name' => 'alpha_vantage', 'provider' => $this->alphaVantage],
        ];
    }

    /**
     * @param  array{open?: float|null, close: float, high?: float|null, low?: float|null, previous_close?: float|null, quoted_at?: ?\Illuminate\Support\Carbon}  $quote
     */
    private function isStalePremarketQuote(float $price, array $quote, ?float $referenceClose): bool
    {
        if (! UsMarketSession::isPremarketWindow()) {
            return false;
        }

        $quotedAt = $quote['quoted_at'] ?? null;

        if ($quotedAt instanceof \Illuminate\Support\Carbon) {
            $premarketStart = now('America/New_York')->startOfDay()->setTime(
                UsMarketSession::PREMARKET_START_HOUR,
                UsMarketSession::PREMARKET_START_MINUTE,
            );

            if ($quotedAt->lessThan($premarketStart)) {
                return true;
            }
        }

        if ($referenceClose !== null && round($price, 2) === round($referenceClose, 2)) {
            return true;
        }

        $providerPreviousClose = $quote['previous_close'] ?? null;

        return $providerPreviousClose !== null
            && round($price, 2) === round((float) $providerPreviousClose, 2);
    }
}
