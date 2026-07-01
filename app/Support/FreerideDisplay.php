<?php

namespace App\Support;

class FreerideDisplay
{
    /**
     * @return array{percentage: float, dollars: float}|null
     */
    public static function gapToFreeride(float $entry, float $currentSl, float $quantity): ?array
    {
        if ($currentSl >= $entry) {
            return null;
        }

        $perShare = $entry - $currentSl;

        if ($perShare <= 0) {
            return null;
        }

        return [
            'percentage' => ($perShare / $entry) * 100,
            'dollars' => $perShare * $quantity,
        ];
    }

    public static function distanceSubtext(?array $gap): ?string
    {
        if ($gap === null) {
            return null;
        }

        return 'Nog '.number_format($gap['percentage'], 2).'% tot Freeride';
    }
}
