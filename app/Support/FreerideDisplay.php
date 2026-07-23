<?php

namespace App\Support;

use App\Models\Position;

class FreerideDisplay
{
    /**
     * @return array{percentage: float, dollars: float}|null
     */
    public static function gapToFreeride(float $entry, float $currentSl, float $quantity, bool $isShort = false): ?array
    {
        if ($isShort) {
            if ($currentSl <= $entry) {
                return null;
            }

            $perShare = $currentSl - $entry;
        } else {
            if ($currentSl >= $entry) {
                return null;
            }

            $perShare = $entry - $currentSl;
        }

        if ($perShare <= 0 || $entry <= 0) {
            return null;
        }

        return [
            'percentage' => ($perShare / $entry) * 100,
            'dollars' => $perShare * $quantity,
        ];
    }

    /**
     * @return array{percentage: float, dollars: float}|null
     */
    public static function gapForPosition(Position $position): ?array
    {
        if ($position->entry_price === null || $position->current_sl === null || $position->quantity === null) {
            return null;
        }

        return self::gapToFreeride(
            (float) $position->entry_price,
            (float) $position->current_sl,
            (float) $position->quantity,
            $position->isShort(),
        );
    }

    public static function distanceSubtext(?array $gap): ?string
    {
        if ($gap === null) {
            return null;
        }

        return 'Nog '.number_format($gap['percentage'], 2).'% tot Freeride';
    }

    public static function compactLabel(?array $gap): string
    {
        if ($gap === null) {
            return '✓';
        }

        return number_format($gap['percentage'], 2).'%';
    }

    public static function compactTooltip(?array $gap): string
    {
        if ($gap === null) {
            return 'Freeride secured — stop-loss staat op of voorbij entry.';
        }

        return self::distanceSubtext($gap)
            ?? 'Nog afstand tot freeride (SL op entry).';
    }
}
