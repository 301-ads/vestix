<?php

namespace App\Support\Kluis;

use App\Enums\KluisClimate;

readonly class KluisThermometerReading
{
    public function __construct(
        public KluisClimate $climate,
        public float $deviationPct,
        public float $close,
        public float $sma200,
        public string $ticker,
        public ?string $resolvedSymbol = null,
    ) {}

    public function message(): string
    {
        return $this->climate->orderMessage($this->deviationPct);
    }
}
