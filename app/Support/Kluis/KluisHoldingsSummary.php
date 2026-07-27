<?php

namespace App\Support\Kluis;

readonly class KluisHoldingsSummary
{
    public function __construct(
        public float $shares,
        public float $costBasis,
        public float $notional,
        public float $fees,
        public int $transactionCount,
        public ?float $livePrice,
        public ?float $holdingsValue,
        public ?float $unrealizedPnl,
        public float $dryPowder,
        public ?float $totalStrategic,
        public ?string $priceSymbol = null,
    ) {}

    public function hasLivePrice(): bool
    {
        return $this->livePrice !== null && $this->holdingsValue !== null;
    }
}
