<?php

namespace App\Services\Ibkr;

use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Collection;

class IbkrPositionReconciler
{
    /**
     * @return list<array{
     *     type: 'qty_drift'|'ghost_vestix'|'ghost_ibkr',
     *     ticker: string,
     *     vestix_qty: float|null,
     *     ibkr_qty: float|null,
     *     position_id: int|null,
     *     message: string
     * }>
     */
    public function mismatches(User $user): array
    {
        $ibkrBySymbol = $this->ibkrQuantities($user);

        if ($ibkrBySymbol === []) {
            return [];
        }

        $open = Position::query()
            ->open()
            ->forUser($user->id)
            ->get();

        $mismatches = [];
        $matchedSymbols = [];

        foreach ($open as $position) {
            $symbol = strtoupper((string) $position->ticker);
            $vestixQty = (float) ($position->remaining_quantity ?? $position->quantity ?? 0);
            $ibkrQty = $ibkrBySymbol[$symbol] ?? null;

            if ($ibkrQty === null) {
                $mismatches[] = [
                    'type' => 'ghost_vestix',
                    'ticker' => $symbol,
                    'vestix_qty' => $vestixQty,
                    'ibkr_qty' => null,
                    'position_id' => $position->id,
                    'message' => "{$symbol}: open in Vestix, maar niet (meer) in je IBKR Flex-rapport. Check of je de positie hebt gesloten of of Flex nog moet syncen.",
                ];

                continue;
            }

            $matchedSymbols[$symbol] = true;

            if (abs($vestixQty - $ibkrQty) > 0.0001) {
                $mismatches[] = [
                    'type' => 'qty_drift',
                    'ticker' => $symbol,
                    'vestix_qty' => $vestixQty,
                    'ibkr_qty' => $ibkrQty,
                    'position_id' => $position->id,
                    'message' => sprintf(
                        '%s: Vestix telt %.4f stuks, IBKR Flex %.4f — neem IBKR over als Flex klopt.',
                        $symbol,
                        $vestixQty,
                        $ibkrQty,
                    ),
                ];
            }
        }

        foreach ($ibkrBySymbol as $symbol => $ibkrQty) {
            if (isset($matchedSymbols[$symbol])) {
                continue;
            }

            if (abs($ibkrQty) < 0.0001) {
                continue;
            }

            $mismatches[] = [
                'type' => 'ghost_ibkr',
                'ticker' => $symbol,
                'vestix_qty' => null,
                'ibkr_qty' => $ibkrQty,
                'position_id' => null,
                'message' => "{$symbol}: staat in IBKR Flex, maar nog niet als open positie in Vestix. Activeer of log de fill in Vestix.",
            ];
        }

        return $mismatches;
    }

    /**
     * Apply qty from IBKR onto a Vestix open position (user-confirmed).
     */
    public function acceptQuantity(Position $position, float $ibkrQty): void
    {
        if ($position->hasScaledOut()) {
            $scaled = (float) $position->scaled_out_quantity;
            $position->update([
                'quantity' => $ibkrQty + $scaled,
                'data_source_label' => 'broker-synced',
                'execution_truth_state' => \App\Enums\ExecutionTruthState::SyncedPartial->value,
            ]);

            return;
        }

        $position->update([
            'quantity' => $ibkrQty,
            'data_source_label' => 'broker-synced',
            'execution_truth_state' => \App\Enums\ExecutionTruthState::SyncedOpen->value,
        ]);
    }

    /**
     * @return array<string, float> symbol => signed quantity
     */
    private function ibkrQuantities(User $user): array
    {
        $rows = $user->ibkr_open_positions;

        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $bySymbol = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));

            if ($symbol === '') {
                continue;
            }

            $qty = (float) ($row['quantity'] ?? 0);
            $bySymbol[$symbol] = ($bySymbol[$symbol] ?? 0) + $qty;
        }

        return $bySymbol;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function mismatchesForUserId(int $userId): Collection
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return collect();
        }

        return collect($this->mismatches($user));
    }
}
