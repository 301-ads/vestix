<?php

namespace App\Contracts;

interface QuoteProvider
{
    public function fetchLivePrice(string $ticker): ?float;

    public function fetchPremarketPrice(string $ticker, ?float $referenceClose = null): ?float;

    /**
     * @return array{open: float|null, close: float, high: float|null, low: float|null, previous_close?: float|null}|null
     */
    public function fetchSessionQuote(string $ticker): ?array;
}
