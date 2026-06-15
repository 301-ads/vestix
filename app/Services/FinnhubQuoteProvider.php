<?php

namespace App\Services;

use App\Contracts\QuoteProvider;

class FinnhubQuoteProvider implements QuoteProvider
{
    public function __construct(private FinnhubService $finnhub) {}

    public function fetchLivePrice(string $ticker): ?float
    {
        return $this->finnhub->fetchQuote($ticker)['close'] ?? null;
    }

    public function fetchSessionQuote(string $ticker): ?array
    {
        return $this->finnhub->fetchQuote($ticker);
    }
}
