<?php

namespace App\Support;

class SniperSetupFilter
{
    /**
     * @param  array{
     *     open: float,
     *     close: float,
     *     high?: float,
     *     low?: float,
     *     sma10: float,
     *     sma20: float,
     *     sma20FiveDaysAgo?: float|null,
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
     * @param  array{open: float, close: float, high?: float, low?: float, sma10: float, sma20: float, sma20FiveDaysAgo?: float|null, sma50: float, rsi14: float}  $inputs
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

        if (! self::passesLongSmaSlope($inputs)) {
            return false;
        }

        if (! self::passesCandleAnatomy($inputs, short: false)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{open: float, close: float, high?: float, low?: float, sma10: float, sma20: float, sma50: float, rsi14: float}  $inputs
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

        if (! self::passesCandleAnatomy($inputs, short: true)) {
            return false;
        }

        return true;
    }

    public static function minPrice(): float
    {
        return (float) config('vestix.sniper_scanner.min_price', 10.0);
    }

    public static function passesMinPrice(float $close): bool
    {
        return $close >= self::minPrice();
    }

    /**
     * When sma20FiveDaysAgo is present, enforce the scorecard long-slope hard-fail.
     * Legacy callers without the key keep prior math behavior.
     *
     * @param  array{sma20: float, sma20FiveDaysAgo?: float|null}  $inputs
     */
    private static function passesLongSmaSlope(array $inputs): bool
    {
        if (! array_key_exists('sma20FiveDaysAgo', $inputs)) {
            return true;
        }

        return ScoutSetupScorecard::longSlopeFailReason([
            'latest_sma_20' => $inputs['sma20'],
            'sma_20_five_days_ago' => $inputs['sma20FiveDaysAgo'],
        ]) === null;
    }

    /**
     * @param  array{open: float, close: float, high?: float, low?: float}  $inputs
     */
    private static function passesCandleAnatomy(array $inputs, bool $short): bool
    {
        if (! CandleAnatomy::enabled()) {
            return true;
        }

        $high = isset($inputs['high']) ? (float) $inputs['high'] : null;
        $low = isset($inputs['low']) ? (float) $inputs['low'] : null;

        if ($high === null || $low === null) {
            // Legacy callers without OHLC high/low keep prior math behavior.
            return true;
        }

        return CandleAnatomy::passesBar([
            'open' => (float) $inputs['open'],
            'high' => $high,
            'low' => $low,
            'close' => (float) $inputs['close'],
        ], $short);
    }
}
