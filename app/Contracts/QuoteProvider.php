<?php

namespace App\Contracts;

interface QuoteProvider
{
    public function fetchLivePrice(string $ticker): ?float;

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null}|null
     */
    public function fetchSessionQuote(string $ticker): ?array;
}
