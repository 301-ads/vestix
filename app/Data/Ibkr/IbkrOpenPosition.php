<?php

namespace App\Data\Ibkr;

final readonly class IbkrOpenPosition
{
    public function __construct(
        public string $symbol,
        public float $quantity,
        public ?float $averageCost = null,
    ) {}

    /**
     * @return array{symbol: string, quantity: float, average_cost: float|null}
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'quantity' => $this->quantity,
            'average_cost' => $this->averageCost,
        ];
    }

    /**
     * @param  array{symbol?: mixed, quantity?: mixed, average_cost?: mixed, averageCost?: mixed}  $row
     */
    public static function fromArray(array $row): self
    {
        $averageCost = $row['average_cost'] ?? $row['averageCost'] ?? null;

        return new self(
            symbol: (string) ($row['symbol'] ?? ''),
            quantity: (float) ($row['quantity'] ?? 0),
            averageCost: $averageCost !== null && (float) $averageCost > 0
                ? round((float) $averageCost, 4)
                : null,
        );
    }
}
