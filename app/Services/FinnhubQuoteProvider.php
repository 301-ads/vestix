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

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null}|null
     */
    public function fetchSessionQuote(string $ticker): ?array
    {
        return $this->finnhub->fetchQuote($ticker);
    }
}
