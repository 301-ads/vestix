<?php

namespace App\Services;

use App\Contracts\QuoteProvider;

class AlphaVantageQuoteProvider implements QuoteProvider
{
    public function __construct(private AlphaVantageService $alphaVantage) {}

    public function fetchLivePrice(string $ticker): ?float
    {
        return $this->alphaVantage->fetchQuote($ticker);
    }

    public function fetchPremarketPrice(string $ticker, ?float $referenceClose = null): ?float
    {
        return $this->fetchLivePrice($ticker);
    }

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null}|null
     */
    public function fetchSessionQuote(string $ticker): ?array
    {
        return $this->alphaVantage->fetchGlobalQuote($ticker);
    }
}
