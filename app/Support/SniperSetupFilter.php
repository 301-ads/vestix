<?php

namespace App\Support;

class SniperSetupFilter
{
    /**
     * @param  array{
     *     open: float,
     *     close: float,
     *     sma10: float,
     *     sma20: float,
     *     sma50: float,
     *     rsi14: float,
     * }  $inputs
     * @return 'long'|'short'|null
     */
    public static function evaluate(array $inputs): ?string
    {
        if (self::passesLong($inputs)) {
            return 'long';
        }

        if (self::passesShort($inputs)) {
            return 'short';
        }

        return null;
    }

    /**
     * @param  array{open: float, close: float, sma10: float, sma20: float, sma50: float, rsi14: float}  $inputs
     */
    public static function passesLong(array $inputs): bool
    {
        $open = $inputs['open'];
        $close = $inputs['close'];
        $sma10 = $inputs['sma10'];
        $sma20 = $inputs['sma20'];
        $sma50 = $inputs['sma50'];
        $rsi = $inputs['rsi14'];

        if ($sma20 <= $sma50 || $sma10 <= $sma20 || $close >= $sma10) {
            return false;
        }

        if ($close <= $open) {
            return false;
        }

        if ($rsi < 40.0 || $rsi > 55.0) {
            return false;
        }

        if ($close < $sma20 || $close > $sma20 * 1.03) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{open: float, close: float, sma10: float, sma20: float, sma50: float, rsi14: float}  $inputs
     */
    public static function passesShort(array $inputs): bool
    {
        $open = $inputs['open'];
        $close = $inputs['close'];
        $sma10 = $inputs['sma10'];
        $sma20 = $inputs['sma20'];
        $sma50 = $inputs['sma50'];
        $rsi = $inputs['rsi14'];

        if ($sma20 >= $sma50 || $sma10 >= $sma20 || $close <= $sma10) {
            return false;
        }

        if ($close >= $open) {
            return false;
        }

        if ($rsi < 40.0 || $rsi > 60.0) {
            return false;
        }

        if ($close > $sma20 || $close < $sma20 * 0.97) {
            return false;
        }

        return true;
    }
}
