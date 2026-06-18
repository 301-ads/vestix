<?php

namespace App\Support;

final class ClosePriceTrend
{
    /**
     * @param  array<int, float|int|string>  $storedHistory
     */
    public static function resolvePreviousSessionClose(float $currentClose, array $storedHistory): ?float
    {
        if ($storedHistory === []) {
            return null;
        }

        $history = array_values(array_map(
            static fn (mixed $price): float => round((float) $price, 2),
            $storedHistory,
        ));

        $storedLast = $history[count($history) - 1];
        $currentClose = round($currentClose, 2);

        if (count($history) >= 2 && abs($storedLast - $currentClose) < 0.01) {
            return $history[count($history) - 2];
        }

        if (abs($storedLast - $currentClose) >= 0.01) {
            return $storedLast;
        }

        return null;
    }

    /**
     * @param  array<int, float|int|string>  $storedHistory
     * @return array{
     *     description: string,
     *     icon: string,
     *     color: string,
     *     changePct: float,
     * }|null
     */
    public static function resolveDayChange(float $currentClose, array $storedHistory): ?array
    {
        $previousClose = self::resolvePreviousSessionClose($currentClose, $storedHistory);

        if ($previousClose === null || $previousClose <= 0) {
            return null;
        }

        $currentClose = round($currentClose, 2);
        $changePct = (($currentClose - $previousClose) / $previousClose) * 100;
        $isPositive = $changePct >= 0;
        $prefix = $isPositive ? '+' : '−';

        return [
            'description' => $prefix.number_format(abs($changePct), 2).'% t.o.v. slotkoers',
            'icon' => $isPositive ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down',
            'color' => $isPositive ? 'success' : 'danger',
            'changePct' => $changePct,
        ];
    }
}
