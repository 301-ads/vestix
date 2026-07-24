<?php

namespace App\Support\Kluis;

use App\Enums\KluisClimate;

readonly class KluisOrderPlan
{
    public function __construct(
        public KluisClimate $climate,
        public float $budgetInput,
        public float $etfAmount,
        public float $dryPowderDelta,
        public float $dryPowderAfter,
        public string $message,
    ) {}

    public function cashReserveAmount(): float
    {
        return max(0.0, $this->dryPowderDelta);
    }

    public function dryPowderDeployed(): float
    {
        return max(0.0, -$this->dryPowderDelta);
    }
}
