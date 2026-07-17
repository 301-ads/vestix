<?php

namespace App\Services\Ibkr;

use App\Contracts\IbkrAccountReader;
use App\Models\User;

/**
 * Local / test reader — no HTTP to IBKR.
 * Prefers stub config, then persisted sync fields, then trading_bankroll / baseline.
 */
class StubIbkrAccountReader implements IbkrAccountReader
{
    public function netLiquidationValue(User $user): float
    {
        $configured = config('vestix.ibkr.stub.net_liquidation');

        if ($configured !== null && $configured !== '') {
            return round((float) $configured, 2);
        }

        if ($user->ibkr_net_liquidation !== null && (float) $user->ibkr_net_liquidation > 0) {
            return round((float) $user->ibkr_net_liquidation, 2);
        }

        if ($user->trading_bankroll !== null && (float) $user->trading_bankroll > 0) {
            return round((float) $user->trading_bankroll, 2);
        }

        if ($user->baseline_capital !== null && (float) $user->baseline_capital > 0) {
            return round((float) $user->baseline_capital, 2);
        }

        return 0.0;
    }

    public function availableFunds(User $user): float
    {
        $configured = config('vestix.ibkr.stub.available_funds');

        if ($configured !== null && $configured !== '') {
            return round((float) $configured, 2);
        }

        if ($user->ibkr_available_funds !== null) {
            return round((float) $user->ibkr_available_funds, 2);
        }

        return $this->netLiquidationValue($user);
    }

    public function settledCash(User $user): float
    {
        $configured = config('vestix.ibkr.stub.settled_cash');

        if ($configured !== null && $configured !== '') {
            return round((float) $configured, 2);
        }

        if ($user->ibkr_settled_cash !== null) {
            return round((float) $user->ibkr_settled_cash, 2);
        }

        return $this->availableFunds($user);
    }

    public function deployableCapital(User $user): float
    {
        return round(min($this->availableFunds($user), $this->settledCash($user)), 2);
    }

    public function openPositions(User $user): array
    {
        $configured = config('vestix.ibkr.stub.open_positions', []);

        if (is_array($configured) && $configured !== []) {
            return array_values(array_map(
                fn (array $row): array => [
                    'symbol' => (string) ($row['symbol'] ?? ''),
                    'quantity' => (float) ($row['quantity'] ?? 0),
                ],
                $configured,
            ));
        }

        $persisted = $user->ibkr_open_positions;

        return is_array($persisted) ? array_values($persisted) : [];
    }

    public function openOrders(User $user): array
    {
        $configured = config('vestix.ibkr.stub.open_orders', []);

        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        $persisted = $user->ibkr_open_orders;

        return is_array($persisted) ? array_values($persisted) : [];
    }
}
