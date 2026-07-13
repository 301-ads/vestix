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
    ) {}

    public function fetchLivePrice(string $ticker): ?float
    {
        $quote = $this->fetchSessionQuoteWithProvider($ticker);

        return $quote['close'] ?? null;
    }

    public function fetchPremarketPrice(string $ticker, ?float $referenceClose = null): ?float
    {
        if (! PremarketQuoteCapability::hasLivePremarketSource()) {
            Log::info('Pre-market quote skipped — no entitled live data source on current API plan.', [
                'ticker' => $ticker,
            ]);

            return null;
        }

        if (PremarketQuoteCapability::assess($ticker)['finnhub_intraday']) {
            $intradayPrice = $this->finnhub->fetchIntradayClose($ticker);

            if ($intradayPrice !== null && ! $this->isStalePremarketQuote($intradayPrice, [], $referenceClose)) {
                return $intradayPrice;
            }
        }

        foreach ($this->premarketProviders() as $entry) {
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
    private function premarketProviders(): array
    {
        $providers = [];

        if (PremarketQuoteCapability::assess()['polygon_realtime']) {
            $providers[] = ['name' => 'polygon', 'provider' => $this->polygon];
        }

        $providers[] = ['name' => 'finnhub', 'provider' => $this->finnhub];
        $providers[] = ['name' => 'alpha_vantage', 'provider' => $this->alphaVantage];

        return $providers;
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

        if ($referenceClose !== null && abs($price - $referenceClose) < 0.01) {
            return true;
        }

        $providerPreviousClose = $quote['previous_close'] ?? null;

        return $providerPreviousClose !== null && abs($price - (float) $providerPreviousClose) < 0.01;
    }
}
