<?php

namespace App\Contracts;

interface QuoteProvider
{
    public function fetchLivePrice(string $ticker): ?float;
}
