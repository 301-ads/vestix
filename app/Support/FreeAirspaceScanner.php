<?php

namespace App\Support;

use App\Enums\TradeDirection;

/**
 * Detects SMA-50 / SMA-200 blockades between entry and Target 1.
 */
class FreeAirspaceScanner
{
    /**
     * @param  array{
     *     direction?: TradeDirection|string|null,
     *     entry_price?: float|null,
     *     stop_price?: float|null,
     *     target_1_price?: float|null,
     *     target_1_rr?: float|null,
     *     signal_low?: float|null,
     *     signal_high?: float|null,
     *     latest_atr_14?: float|null,
     *     latest_sma_50?: float|null,
     *     latest_sma_200?: float|null,
     * }  $inputs
     */
    public static function blockadeReason(array $inputs): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $resolved = self::resolveBracket($inputs);

        if ($resolved === null) {
            return null;
        }

        ['entry' => $entry, 'target1' => $target1, 'short' => $short] = $resolved;

        $blockers = [];

        foreach (['SMA-50' => self::toFloat($inputs['latest_sma_50'] ?? null), 'SMA-200' => self::toFloat($inputs['latest_sma_200'] ?? null)] as $label => $sma) {
            if ($sma === null) {
                // SMA-200 often unavailable (<200 bars); skip missing levels rather than hard-fail.
                continue;
            }

            if (self::levelBlocksPath($sma, $entry, $target1, $short)) {
                $blockers[] = $label;
            }
        }

        if ($blockers === []) {
            return null;
        }

        return sprintf(
            'Blokkade in het luchtruim (%s tussen entry %.2f en Target 1 %.2f)',
            implode('/', $blockers),
            $entry,
            $target1,
        );
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{entry: float, target1: float, short: bool}|null
     */
    public static function resolveBracket(array $inputs): ?array
    {
        $short = self::isShort($inputs);
        $entry = self::toFloat($inputs['entry_price'] ?? null);
        $stop = self::toFloat($inputs['stop_price'] ?? null);
        $target1 = self::toFloat($inputs['target_1_price'] ?? null);
        $atr = self::toFloat($inputs['latest_atr_14'] ?? null);
        $signalLow = self::toFloat($inputs['signal_low'] ?? null);
        $signalHigh = self::toFloat($inputs['signal_high'] ?? null);

        if ($entry === null) {
            $entry = $short
                ? self::sellStop($signalLow, $atr)
                : self::buyStop($signalHigh, $atr);
        }

        if ($stop === null) {
            $stop = $short
                ? self::buyStop($signalHigh, $atr)
                : self::sellStop($signalLow, $atr);
        }

        if ($entry === null || $stop === null) {
            return null;
        }

        if ($target1 === null) {
            $risk = abs($entry - $stop);

            if ($risk <= 0) {
                return null;
            }

            $rr = self::toFloat($inputs['target_1_rr'] ?? null) ?? self::defaultTarget1Rr();
            $target1 = $short
                ? $entry - ($risk * $rr)
                : $entry + ($risk * $rr);
        }

        if ($target1 <= 0) {
            return null;
        }

        return [
            'entry' => $entry,
            'target1' => $target1,
            'short' => $short,
        ];
    }

    public static function levelBlocksPath(float $level, float $entry, float $target1, bool $short): bool
    {
        if ($short) {
            return $level < $entry && $level > $target1;
        }

        return $level > $entry && $level < $target1;
    }

    public static function enabled(): bool
    {
        return (bool) config('vestix.sniper_scorecard.free_airspace_enabled', true);
    }

    public static function hardFail(): bool
    {
        return (bool) config('vestix.sniper_scorecard.free_airspace_hard_fail', true);
    }

    public static function defaultTarget1Rr(): float
    {
        return (float) config('vestix.scale_out.target_1_rr', 2.0);
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

    private static function buyStop(?float $high, ?float $atr): ?float
    {
        if ($high === null || $atr === null || $high <= 0 || $atr <= 0) {
            return null;
        }

        return round($high + (0.10 * $atr), 2);
    }

    private static function sellStop(?float $low, ?float $atr): ?float
    {
        if ($low === null || $atr === null || $low <= 0 || $atr <= 0) {
            return null;
        }

        return round(max(0.01, $low - (0.10 * $atr)), 2);
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
