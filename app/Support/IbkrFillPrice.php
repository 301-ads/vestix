<?php

namespace App\Support;

use App\Models\Position;
use App\Models\User;

/**
 * Resolve broker average fill for activation — never the planned buy-stop / limit.
 */
class IbkrFillPrice
{
    public static function averageCostForTicker(?User $user, string $ticker): ?float
    {
        if ($user === null) {
            return null;
        }

        $symbol = strtoupper(trim($ticker));
        $rows = $user->ibkr_open_positions;

        if (! is_array($rows) || $symbol === '') {
            return null;
        }

        $qtyWeightedCost = 0.0;
        $qtyTotal = 0.0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (strtoupper(trim((string) ($row['symbol'] ?? ''))) !== $symbol) {
                continue;
            }

            $qty = abs((float) ($row['quantity'] ?? 0));
            $cost = $row['average_cost'] ?? $row['averageCost'] ?? null;

            if ($qty <= 0 || $cost === null || (float) $cost <= 0) {
                continue;
            }

            $qtyWeightedCost += $qty * (float) $cost;
            $qtyTotal += $qty;
        }

        if ($qtyTotal <= 0) {
            return null;
        }

        return round($qtyWeightedCost / $qtyTotal, 2);
    }

    public static function suggestedFillForScout(Position $scout): ?float
    {
        $scout->loadMissing('user');

        return self::averageCostForTicker($scout->user, $scout->ticker);
    }

    public static function plannedBuyStop(?Position $scout): ?float
    {
        if ($scout === null || $scout->entry_price === null) {
            return null;
        }

        return round((float) $scout->entry_price, 2);
    }

    public static function plannedLimit(?Position $scout): ?float
    {
        $stop = self::plannedBuyStop($scout);

        if ($stop === null) {
            return null;
        }

        return StopLimitBuffer::limitPriceForDirection($stop, $scout->tradeDirection());
    }
}
