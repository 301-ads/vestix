<?php

namespace App\Data\Ibkr;

final readonly class IbkrOpenOrder
{
    public function __construct(
        public string $symbol,
        public float $quantity,
        public string $side,
        public string $orderType,
        public string $status,
        public ?float $limitPrice = null,
        public ?float $stopPrice = null,
        public ?string $brokerOrderId = null,
    ) {}

    /**
     * @return array{
     *     symbol: string,
     *     quantity: float,
     *     side: string,
     *     order_type: string,
     *     status: string,
     *     limit_price: float|null,
     *     stop_price: float|null,
     *     broker_order_id: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'quantity' => $this->quantity,
            'side' => $this->side,
            'order_type' => $this->orderType,
            'status' => $this->status,
            'limit_price' => $this->limitPrice,
            'stop_price' => $this->stopPrice,
            'broker_order_id' => $this->brokerOrderId,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            symbol: (string) ($row['symbol'] ?? ''),
            quantity: (float) ($row['quantity'] ?? 0),
            side: (string) ($row['side'] ?? ''),
            orderType: (string) ($row['order_type'] ?? $row['orderType'] ?? ''),
            status: (string) ($row['status'] ?? ''),
            limitPrice: isset($row['limit_price']) || isset($row['limitPrice'])
                ? (float) ($row['limit_price'] ?? $row['limitPrice'])
                : null,
            stopPrice: isset($row['stop_price']) || isset($row['stopPrice'])
                ? (float) ($row['stop_price'] ?? $row['stopPrice'])
                : null,
            brokerOrderId: isset($row['broker_order_id']) || isset($row['brokerOrderId'])
                ? (string) ($row['broker_order_id'] ?? $row['brokerOrderId'])
                : null,
        );
    }
}
