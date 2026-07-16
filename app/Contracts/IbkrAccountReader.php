<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Read-only IBKR account surface (the "Big 3").
 *
 * Phase 2: FlexIbkrAccountReader will fetch NLV, Available Funds, and open
 * positions via the IBKR Flex Web Service (token + nightly report).
 *
 * Phase 3: order placement belongs in a separate IbkrExecutionService
 * (Client Portal) — never in this reader.
 */
interface IbkrAccountReader
{
    public function netLiquidationValue(User $user): float;

    public function availableFunds(User $user): float;

    /**
     * @return list<array{symbol: string, quantity: float}>
     */
    public function openPositions(User $user): array;
}
