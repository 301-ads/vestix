<?php

namespace App\Support;

final class SlPriceProximity
{
    /** Buffer smaller than this many ATR units is critically tight (red). */
    private const float DANGER_ATR_MULTIPLIER = 0.35;

    /** Buffer smaller than this many ATR units is getting tight (yellow). */
    private const float WARNING_ATR_MULTIPLIER = 1.0;

    /** Percentage fallback when ATR is unavailable: critically tight (red). */
    private const float DANGER_BUFFER_PERCENT = 0.75;

    /** Percentage fallback when ATR is unavailable: getting tight (yellow). */
    private const float WARNING_BUFFER_PERCENT = 2.0;

    public static function buffer(float $close, float $sl): float
    {
        return $close - $sl;
    }

    public static function bufferPercentage(float $close, float $sl): float
    {
        if ($close <= 0) {
            return 0;
        }

        return (self::buffer($close, $sl) / $close) * 100;
    }

    public static function bufferInAtrUnits(float $close, float $sl, float $atr): ?float
    {
        if ($atr <= 0) {
            return null;
        }

        return self::buffer($close, $sl) / $atr;
    }

    public static function color(float $close, float $sl, ?float $atr = null): string
    {
        if ($close <= 0) {
            return 'gray';
        }

        $buffer = self::buffer($close, $sl);

        if ($buffer <= 0) {
            return 'danger';
        }

        if ($atr !== null && $atr > 0) {
            $bufferAtr = $buffer / $atr;

            return match (true) {
                $bufferAtr < self::DANGER_ATR_MULTIPLIER => 'danger',
                $bufferAtr < self::WARNING_ATR_MULTIPLIER => 'warning',
                default => 'success',
            };
        }

        $bufferPct = self::bufferPercentage($close, $sl);

        return match (true) {
            $bufferPct < self::DANGER_BUFFER_PERCENT => 'danger',
            $bufferPct < self::WARNING_BUFFER_PERCENT => 'warning',
            default => 'success',
        };
    }
}
