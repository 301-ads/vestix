<?php

namespace App\Support;

use App\Services\PolygonDailyBarService;

class PreBounceExtensionCalculator
{
    public static function extensionThreshold(): float
    {
        return (float) config('vestix.sniper_scorecard.extension_atr_threshold', 2.0);
    }

    /**
     * @param  array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     */
    public static function calculate(array $bars, float $sma20, float $atr14, int $lookbackDays = 20): ?float
    {
        if ($atr14 <= 0 || $sma20 <= 0 || count($bars) < 2) {
            return null;
        }

        $bounceIndex = self::resolveBounceIndex($bars, $sma20);

        if ($bounceIndex === null) {
            return null;
        }

        $startIndex = max(0, $bounceIndex - $lookbackDays);
        $maxExtension = null;

        for ($index = $startIndex; $index < $bounceIndex; $index++) {
            $bar = $bars[$index];
            $close = (float) $bar['close'];

            if ($close <= $sma20 * 1.01) {
                continue;
            }

            $extension = ((float) $bar['high'] - $sma20) / $atr14;

            if ($extension > 0 && ($maxExtension === null || $extension > $maxExtension)) {
                $maxExtension = $extension;
            }
        }

        return $maxExtension !== null ? round($maxExtension, 2) : null;
    }

    /**
     * @param  array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     */
    private static function resolveBounceIndex(array $bars, float $sma20): ?int
    {
        $lastIndex = count($bars) - 1;
        $lastBar = $bars[$lastIndex];

        if (PolygonDailyBarService::isBounceDay($lastBar['low'], $lastBar['close'], $sma20)) {
            return $lastIndex;
        }

        for ($index = $lastIndex; $index >= 0; $index--) {
            $bar = $bars[$index];

            if (PolygonDailyBarService::isBounceDay($bar['low'], $bar['close'], $sma20)) {
                return $index;
            }
        }

        return $lastIndex > 0 ? $lastIndex : null;
    }
}
