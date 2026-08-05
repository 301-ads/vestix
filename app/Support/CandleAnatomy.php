<?php

namespace App\Support;

use App\Enums\TradeDirection;

/**
 * Closing-strength check: signal candle must close in the dominant quartile.
 */
class CandleAnatomy
{
    /**
     * @param  array{
     *     direction?: TradeDirection|string|null,
     *     latest_open_price?: float|null,
     *     latest_close_price?: float|null,
     *     signal_low?: float|null,
     *     signal_high?: float|null,
     * }  $inputs
     */
    public static function failReason(array $inputs): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $pct = self::closingStrengthPct($inputs);

        if ($pct === null) {
            // Missing OHLC: skip (do not invent a hard fail on incomplete data).
            return null;
        }

        $minPct = self::minClosingPct();

        if ($pct + 1e-9 < $minPct) {
            $short = self::isShort($inputs);

            return $short
                ? sprintf('Röntgenfoto faalt — Close in onderste %.0f%% van de range (min %.0f%%)', $pct, $minPct)
                : sprintf('Röntgenfoto faalt — Close in bovenste %.0f%% van de range (min %.0f%%)', $pct, $minPct);
        }

        return null;
    }

    /**
     * Closing strength as percentage of the candle range (0–100).
     * Long: (close − low) / (high − low) × 100
     * Short: (high − close) / (high − low) × 100
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function closingStrengthPct(array $inputs): ?float
    {
        $high = self::toFloat($inputs['signal_high'] ?? null);
        $low = self::toFloat($inputs['signal_low'] ?? null);
        $close = self::toFloat($inputs['latest_close_price'] ?? null);

        if ($high === null || $low === null || $close === null) {
            return null;
        }

        $range = $high - $low;

        if ($range <= 0) {
            return null;
        }

        $ratio = self::isShort($inputs)
            ? ($high - $close) / $range
            : ($close - $low) / $range;

        return max(0.0, min(100.0, $ratio * 100));
    }

    public static function enabled(): bool
    {
        return (bool) config('vestix.sniper_scorecard.candle_anatomy_enabled', true);
    }

    public static function hardFail(): bool
    {
        return (bool) config('vestix.sniper_scorecard.candle_anatomy_hard_fail', true);
    }

    public static function minClosingPct(): float
    {
        return (float) config('vestix.sniper_scorecard.candle_anatomy_min_closing_pct', 75.0);
    }

    /**
     * @param  array{open: float, high: float, low: float, close: float}  $bar
     */
    public static function passesBar(array $bar, bool $short): bool
    {
        $pct = self::closingStrengthPct([
            'direction' => $short ? TradeDirection::Short : TradeDirection::Long,
            'signal_high' => $bar['high'],
            'signal_low' => $bar['low'],
            'latest_close_price' => $bar['close'],
        ]);

        if ($pct === null) {
            return false;
        }

        return $pct + 1e-9 >= self::minClosingPct();
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private static function isShort(array $inputs): bool
    {
        $direction = $inputs['direction'] ?? TradeDirection::Long;

        if ($direction instanceof TradeDirection) {
            return $direction->isShort();
        }

        return TradeDirection::tryFrom((string) $direction) === TradeDirection::Short;
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
