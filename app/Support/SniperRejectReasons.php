<?php

namespace App\Support;

/**
 * Explain why a ticker failed the native sniper math filter (Free-First learning loop).
 */
class SniperRejectReasons
{
    /**
     * @param  array{
     *     open: float,
     *     close: float,
     *     high?: float,
     *     low?: float,
     *     sma10: float,
     *     sma20: float,
     *     sma50: float,
     *     rsi14: float,
     * }  $inputs
     * @return list<string>
     */
    public static function forInputs(array $inputs): array
    {
        if (SniperSetupFilter::evaluate($inputs) !== null) {
            return [];
        }

        $long = self::longFailures($inputs);
        $short = self::shortFailures($inputs);

        if ($long !== [] && $short !== []) {
            return [
                'Long: '.$long[0],
                'Short: '.$short[0],
                ...array_slice(array_map(fn (string $r): string => 'Long: '.$r, array_slice($long, 1)), 0, 2),
            ];
        }

        return $long !== [] ? $long : $short;
    }

    /**
     * @param  array{open: float, close: float, sma10: float, sma20: float, sma50: float, rsi14: float}  $inputs
     * @return list<string>
     */
    public static function longFailures(array $inputs): array
    {
        $reasons = [];
        $open = $inputs['open'];
        $close = $inputs['close'];
        $sma10 = $inputs['sma10'];
        $sma20 = $inputs['sma20'];
        $sma50 = $inputs['sma50'];
        $rsi = $inputs['rsi14'];

        if ($sma20 <= $sma50) {
            $reasons[] = 'SMA20 niet boven SMA50 (uptrend ontbreekt)';
        }

        if ($sma10 <= $sma20) {
            $reasons[] = 'SMA10 niet boven SMA20';
        }

        if ($close >= $sma10) {
            $reasons[] = 'Close niet onder SMA10 (geen pullback)';
        }

        if ($close <= $open) {
            $reasons[] = 'Geen groene candle';
        }

        if ($rsi < 40.0 || $rsi > 55.0) {
            $reasons[] = sprintf('RSI %.1f buiten 40–55', $rsi);
        }

        if ($close < $sma20) {
            $reasons[] = 'Close onder SMA20';
        } elseif ($close > $sma20 * 1.03) {
            $reasons[] = 'Close >3% boven SMA20 (te extended)';
        }

        return $reasons;
    }

    /**
     * @param  array{open: float, close: float, sma10: float, sma20: float, sma50: float, rsi14: float}  $inputs
     * @return list<string>
     */
    public static function shortFailures(array $inputs): array
    {
        $reasons = [];
        $open = $inputs['open'];
        $close = $inputs['close'];
        $sma10 = $inputs['sma10'];
        $sma20 = $inputs['sma20'];
        $sma50 = $inputs['sma50'];
        $rsi = $inputs['rsi14'];

        if ($sma20 >= $sma50) {
            $reasons[] = 'SMA20 niet onder SMA50 (downtrend ontbreekt)';
        }

        if ($sma10 >= $sma20) {
            $reasons[] = 'SMA10 niet onder SMA20';
        }

        if ($close <= $sma10) {
            $reasons[] = 'Close niet boven SMA10 (geen bounce)';
        }

        if ($close >= $open) {
            $reasons[] = 'Geen rode candle';
        }

        if ($rsi < 40.0 || $rsi > 60.0) {
            $reasons[] = sprintf('RSI %.1f buiten 40–60', $rsi);
        }

        if ($close > $sma20) {
            $reasons[] = 'Close boven SMA20';
        } elseif ($close < $sma20 * 0.97) {
            $reasons[] = 'Close >3% onder SMA20 (te extended)';
        }

        return $reasons;
    }
}
