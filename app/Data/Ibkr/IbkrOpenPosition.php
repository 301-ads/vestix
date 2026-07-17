<?php

namespace App\Data\Ibkr;

final readonly class IbkrOpenPosition
{
    public function __construct(
        public string $symbol,
        public float $quantity,
    ) {}

    /**
     * @return array{symbol: string, quantity: float}
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'quantity' => $this->quantity,
        ];
    }

    /**
     * @param  array{symbol?: mixed, quantity?: mixed}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            symbol: (string) ($row['symbol'] ?? ''),
            quantity: (float) ($row['quantity'] ?? 0),
        );
    }
}
