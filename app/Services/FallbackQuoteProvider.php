<?php

namespace App\Services;

use App\Contracts\QuoteProvider;
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
        $providers = [
            ['name' => 'finnhub', 'provider' => $this->finnhub],
            ['name' => 'alpha_vantage', 'provider' => $this->alphaVantage],
            ['name' => 'polygon', 'provider' => $this->polygon],
        ];

        foreach ($providers as $entry) {
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
}
