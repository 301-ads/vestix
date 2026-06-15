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

    public function fetchSessionQuote(string $ticker): ?array
    {
        return $this->alphaVantage->fetchGlobalQuote($ticker);
    }
}
