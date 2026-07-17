<?php

namespace App\Data\Ibkr;

final readonly class IbkrAccountSnapshot
{
    /**
     * @param  list<IbkrOpenPosition>  $openPositions
     * @param  list<IbkrOpenOrder>  $openOrders
     * @param  list<IbkrCashTransaction>  $cashTransactions
     */
    public function __construct(
        public float $netLiquidation,
        public float $availableFunds,
        public float $settledCash,
        public string $baseCurrency,
        public array $openPositions = [],
        public array $openOrders = [],
        public array $cashTransactions = [],
    ) {}

    /**
     * Deployable capital for Smart Sizing: never spend unsettled proceeds.
     */
    public function deployableCapital(): float
    {
        return round(min($this->availableFunds, $this->settledCash), 2);
    }

    /**
     * @return list<array{symbol: string, quantity: float}>
     */
    public function openPositionsAsArrays(): array
    {
        return array_map(
            fn (IbkrOpenPosition $position): array => $position->toArray(),
            $this->openPositions,
        );
    }

    /**
     * @return list<array{
     *     symbol: string,
     *     quantity: float,
     *     side: string,
     *     order_type: string,
     *     status: string,
     *     limit_price: float|null,
     *     stop_price: float|null,
     *     broker_order_id: string|null
     * }>
     */
    public function openOrdersAsArrays(): array
    {
        return array_map(
            fn (IbkrOpenOrder $order): array => $order->toArray(),
            $this->openOrders,
        );
    }
}
