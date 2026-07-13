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

    public function fetchPremarketPrice(string $ticker, ?float $referenceClose = null): ?float
    {
        return $this->fetchLivePrice($ticker);
    }

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null, previous_close: float|null}|null
     */
    public function fetchSessionQuote(string $ticker): ?array
    {
        return $this->finnhub->fetchQuote($ticker);
    }
}
