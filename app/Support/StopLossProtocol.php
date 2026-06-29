<?php

namespace App\Support;

use App\Enums\TrailingStopMode;
use App\Models\Position;

class StopLossProtocol
{
    public static function isPreEarningsWindow(Position $position): bool
    {
        if ($position->status !== 'open') {
            return false;
        }

        $daysUntil = $position->daysUntilEarnings();

        if ($daysUntil === null) {
            return false;
        }

        $windowDays = self::windowDays();

        return $daysUntil >= 0 && $daysUntil <= $windowDays;
    }

    public static function isOverheated(Position $position): bool
    {
        $rsi = $position->scout_rsi;
        $close = $position->latest_close_price;
        $sma = $position->latest_sma_20;

        if ($rsi === null || $rsi === '' || $close === null || $close === '' || $sma === null || $sma === '') {
            return false;
        }

        $sma = (float) $sma;

        if ($sma <= 0) {
            return false;
        }

        $extensionPct = (((float) $close - $sma) / $sma) * 100;

        return (float) $rsi > self::rsiThreshold()
            && $extensionPct > self::smaExtensionPct();
    }

    public static function activeMode(Position $position): TrailingStopMode
    {
        if (self::isPreEarningsWindow($position) && self::isOverheated($position)) {
            return TrailingStopMode::AggressivePreEarnings;
        }

        return TrailingStopMode::Standard;
    }

    public static function computeStandard(mixed $sma, mixed $atr): ?float
    {
        if ($sma === null || $atr === null || $sma === '' || $atr === '') {
            return null;
        }

        return round((float) $sma - ((float) $atr / 2), 2);
    }

    public static function computeAggressive(Position $position): ?float
    {
        return match (self::aggressiveMethod()) {
            'prior_day_low' => self::computeAggressivePriorDayLow($position),
            default => self::computeAggressiveAtr($position),
        };
    }

    public static function resolve(Position $position): ?float
    {
        $standard = self::computeStandard($position->latest_sma_20, $position->latest_atr_14);

        if (self::activeMode($position) !== TrailingStopMode::AggressivePreEarnings) {
            return $standard;
        }

        $aggressive = self::computeAggressive($position);

        if ($aggressive === null) {
            return $standard;
        }

        if ($standard === null) {
            return $aggressive;
        }

        return max($aggressive, $standard);
    }

    public static function resolveForIndicators(mixed $sma, mixed $atr): ?float
    {
        return self::computeStandard($sma, $atr);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function resolveWithOverrides(Position $position, array $overrides = []): ?float
    {
        return self::resolve(self::applyOverrides($position, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function applyOverrides(Position $position, array $overrides = []): Position
    {
        if ($overrides === []) {
            $position->loadMissing('asset');

            return $position;
        }

        $working = $position->replicate();
        $working->exists = $position->exists;
        $working->id = $position->id;

        foreach ($overrides as $key => $value) {
            if ($value !== null && $value !== '') {
                $working->setAttribute($key, $value);
            }
        }

        $position->loadMissing('asset');
        $working->setRelation('asset', $position->asset);

        return $working;
    }

    public static function aggressiveFormulaLabel(): string
    {
        return match (self::aggressiveMethod()) {
            'prior_day_low' => sprintf('low gisteren − %.1f%%', self::priorDayBufferPct()),
            default => sprintf('close − %.1f×ATR', self::atrMultiplier()),
        };
    }

    public static function windowDays(): int
    {
        return (int) config('vestix.pre_earnings_trailing.window_days', EarningsExitDisplay::ALERT_WINDOW_DAYS);
    }

    public static function rsiThreshold(): float
    {
        return (float) config('vestix.pre_earnings_trailing.rsi_threshold', 70);
    }

    public static function smaExtensionPct(): float
    {
        return (float) config('vestix.pre_earnings_trailing.sma_extension_pct', 5.0);
    }

    public static function aggressiveMethod(): string
    {
        return (string) config('vestix.pre_earnings_trailing.aggressive_method', 'atr');
    }

    public static function atrMultiplier(): float
    {
        return (float) config('vestix.pre_earnings_trailing.atr_multiplier', 1.5);
    }

    public static function priorDayBufferPct(): float
    {
        return (float) config('vestix.pre_earnings_trailing.prior_day_buffer_pct', 0.1);
    }

    private static function computeAggressiveAtr(Position $position): ?float
    {
        $close = $position->latest_close_price;
        $atr = $position->latest_atr_14;

        if ($close === null || $close === '' || $atr === null || $atr === '') {
            return null;
        }

        return round((float) $close - (self::atrMultiplier() * (float) $atr), 2);
    }

    private static function computeAggressivePriorDayLow(Position $position): ?float
    {
        $priorDayLow = $position->prior_day_low;

        if ($priorDayLow === null || $priorDayLow === '') {
            return null;
        }

        $bufferFactor = 1 - (self::priorDayBufferPct() / 100);

        return round((float) $priorDayLow * $bufferFactor, 2);
    }
}
