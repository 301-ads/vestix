<?php

namespace App\Services;

use App\Contracts\QuoteProvider;
use Illuminate\Support\Facades\Log;

class FallbackQuoteProvider implements QuoteProvider
{
    public function __construct(
        private PolygonQuoteProvider $polygon,
        private AlphaVantageQuoteProvider $alphaVantage,
    ) {}

    public function fetchLivePrice(string $ticker): ?float
    {
        $price = $this->polygon->fetchLivePrice($ticker);

        if ($price !== null) {
            return $price;
        }

        Log::info('Polygon quote unavailable — falling back to Alpha Vantage.', [
            'ticker' => $ticker,
        ]);

        return $this->alphaVantage->fetchLivePrice($ticker);
    }
}
