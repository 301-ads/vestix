<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Read-only IBKR account surface.
 *
 * Flex (EOD): NLV, Available Funds, Settled Cash, open positions, cash transactions.
 * Client Portal (live read-only): open/working orders.
 *
 * Phase 3: order placement belongs in a separate IbkrExecutionService — never here.
 */
interface IbkrAccountReader
{
    public function netLiquidationValue(User $user): float;

    public function availableFunds(User $user): float;

    public function settledCash(User $user): float;

    /**
     * Conservative deployable capital: min(availableFunds, settledCash).
     */
    public function deployableCapital(User $user): float;

    /**
     * @return list<array{symbol: string, quantity: float}>
     */
    public function openPositions(User $user): array;

    /**
     * @return list<array{
     *     symbol: string,
     *     quantity: float,
     *     side: string,
     *     order_type: string,
     *     status: string,
     *     limit_price?: float|null,
     *     stop_price?: float|null,
     *     broker_order_id?: string|null
     * }>
     */
    public function openOrders(User $user): array;
}
