<?php

namespace App\Services\Ibkr;

use App\Contracts\IbkrAccountReader;
use App\Models\User;
use RuntimeException;

/**
 * Skeleton for Phase 2 Flex Web Service sync.
 * Configure IBKR_FLEX_TOKEN + IBKR_FLEX_QUERY_ID when implementing the fetch/parse.
 */
class FlexIbkrAccountReader implements IbkrAccountReader
{
    public function netLiquidationValue(User $user): float
    {
        throw $this->notConfigured();
    }

    public function availableFunds(User $user): float
    {
        throw $this->notConfigured();
    }

    public function openPositions(User $user): array
    {
        throw $this->notConfigured();
    }

    private function notConfigured(): RuntimeException
    {
        return new RuntimeException(
            'IBKR Flex sync is not configured yet. Set IBKR_READER=stub until Phase 2 Flex Web Service is implemented.',
        );
    }
}
