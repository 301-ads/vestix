<?php

namespace App\Support;

class TechnicalIndicators
{
    /**
     * @param  list<float>  $values
     */
    public static function sma(array $values, int $period): ?float
    {
        if ($period < 1 || count($values) < $period) {
            return null;
        }

        $slice = array_slice($values, -$period);

        return array_sum($slice) / $period;
    }

    /**
     * SMA at a bar offset from the latest close (0 = most recent bar).
     *
     * @param  list<float>  $values
     */
    public static function smaAtOffset(array $values, int $period, int $offsetFromEnd = 0): ?float
    {
        if ($period < 1 || $offsetFromEnd < 0) {
            return null;
        }

        $endIndex = count($values) - 1 - $offsetFromEnd;

        if ($endIndex < $period - 1) {
            return null;
        }

        $slice = array_slice($values, $endIndex - $period + 1, $period);

        return array_sum($slice) / $period;
    }

    /**
     * Wilder RSI on close prices (matches Alpha Vantage close-based RSI).
     *
     * @param  list<float>  $closes
     */
    public static function wilderRsi(array $closes, int $period = 14): ?float
    {
        if ($period < 1 || count($closes) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($index = 1; $index < count($closes); $index++) {
            $change = $closes[$index] - $closes[$index - 1];
            $gains[] = max(0.0, $change);
            $losses[] = max(0.0, -$change);
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($index = $period; $index < count($gains); $index++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$index]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$index]) / $period;
        }

        if ($avgLoss === 0.0) {
            return 100.0;
        }

        $relativeStrength = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $relativeStrength));
    }

    /**
     * Wilder ATR on OHLC bars (matches Alpha Vantage ATR).
     *
     * @param  list<array{high: float, low: float, close: float}>  $bars
     */
    public static function wilderAtr(array $bars, int $period = 14): ?float
    {
        if ($period < 1 || count($bars) < $period + 1) {
            return null;
        }

        $trueRanges = [];

        for ($index = 1; $index < count($bars); $index++) {
            $high = $bars[$index]['high'];
            $low = $bars[$index]['low'];
            $previousClose = $bars[$index - 1]['close'];

            $trueRanges[] = max(
                $high - $low,
                abs($high - $previousClose),
                abs($low - $previousClose),
            );
        }

        if (count($trueRanges) < $period) {
            return null;
        }

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($index = $period; $index < count($trueRanges); $index++) {
            $atr = (($atr * ($period - 1)) + $trueRanges[$index]) / $period;
        }

        return $atr;
    }
}
