<?php

namespace App\Services\Ibkr;

use App\Contracts\IbkrAccountReader;
use App\Models\User;

/**
 * Local / test reader — no HTTP to IBKR.
 */
class StubIbkrAccountReader implements IbkrAccountReader
{
    public function netLiquidationValue(User $user): float
    {
        $configured = config('vestix.ibkr.stub.net_liquidation');

        if ($configured !== null && $configured !== '') {
            return round((float) $configured, 2);
        }

        if ($user->baseline_capital !== null && (float) $user->baseline_capital > 0) {
            return round((float) $user->baseline_capital, 2);
        }

        if ($user->trading_bankroll !== null && (float) $user->trading_bankroll > 0) {
            return round((float) $user->trading_bankroll, 2);
        }

        return 0.0;
    }

    public function availableFunds(User $user): float
    {
        $configured = config('vestix.ibkr.stub.available_funds');

        if ($configured !== null && $configured !== '') {
            return round((float) $configured, 2);
        }

        return $this->netLiquidationValue($user);
    }

    public function openPositions(User $user): array
    {
        $configured = config('vestix.ibkr.stub.open_positions', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_map(
            fn (array $row): array => [
                'symbol' => (string) ($row['symbol'] ?? ''),
                'quantity' => (float) ($row['quantity'] ?? 0),
            ],
            $configured,
        ));
    }
}
