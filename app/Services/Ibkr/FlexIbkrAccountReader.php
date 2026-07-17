<?php

namespace App\Services\Ibkr;

use App\Contracts\IbkrAccountReader;
use App\Models\User;
use RuntimeException;

/**
 * Reads the latest persisted Flex/CP sync snapshot on the user.
 * Live HTTP belongs in IbkrSyncService — this class is the read model.
 */
class FlexIbkrAccountReader implements IbkrAccountReader
{
    public function netLiquidationValue(User $user): float
    {
        $this->ensureSynced($user);

        return round((float) $user->ibkr_net_liquidation, 2);
    }

    public function availableFunds(User $user): float
    {
        $this->ensureSynced($user);

        if ($user->ibkr_available_funds === null) {
            return $this->netLiquidationValue($user);
        }

        return round((float) $user->ibkr_available_funds, 2);
    }

    public function settledCash(User $user): float
    {
        $this->ensureSynced($user);

        if ($user->ibkr_settled_cash === null) {
            return $this->availableFunds($user);
        }

        return round((float) $user->ibkr_settled_cash, 2);
    }

    public function deployableCapital(User $user): float
    {
        return round(min($this->availableFunds($user), $this->settledCash($user)), 2);
    }

    public function openPositions(User $user): array
    {
        $this->ensureSynced($user);

        $persisted = $user->ibkr_open_positions;

        return is_array($persisted) ? array_values($persisted) : [];
    }

    public function openOrders(User $user): array
    {
        $this->ensureSynced($user);

        $persisted = $user->ibkr_open_orders;

        return is_array($persisted) ? array_values($persisted) : [];
    }

    private function ensureSynced(User $user): void
    {
        if ($user->ibkr_net_liquidation === null && $user->ibkr_last_success_at === null) {
            throw new RuntimeException(
                'IBKR Flex sync has not run yet. Run `php artisan vestix:sync-ibkr` or set IBKR_READER=stub.',
            );
        }
    }
}
