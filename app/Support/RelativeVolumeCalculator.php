<?php

namespace App\Support;

use App\Services\PolygonDailyBarService;

class RelativeVolumeCalculator
{
    public static function rvolThreshold(): float
    {
        return (float) config('vestix.sniper_scorecard.rvol_threshold', 1.2);
    }

    public static function formatThresholdPercent(): string
    {
        return self::formatPercent(self::rvolThreshold()) ?? '120%';
    }

    public static function normalizeRatio(float|int|string|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $hadPercentSuffix = is_string($value) && str_contains($value, '%');

        if (is_string($value)) {
            $value = trim(str_replace('%', '', $value));
            $value = str_replace(',', '.', $value);

            if ($value === '') {
                return null;
            }
        }

        $float = (float) $value;

        if ($hadPercentSuffix) {
            return round($float / 100, 4);
        }

        // Form state pollution: display value 88 instead of ratio 0.88.
        if ($float > self::rvolThreshold() * 10 && $float <= 1000) {
            return round($float / 100, 4);
        }

        return $float;
    }

    public static function formatPercent(float|int|string|null $rvol): ?string
    {
        $ratio = self::normalizeRatio($rvol);

        if ($ratio === null) {
            return null;
        }

        return (int) round($ratio * 100).'%';
    }

    /**
     * @param  array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }  $barsPayload
     * @return array{
     *     relative_volume: float|null,
     *     volume_sma_20: int|null,
     *     bounce_volume_above_average: bool,
     *     bounce_day_volume: int|null,
     *     avg_volume_30d: int|null,
     * }
     */
    public static function resolve(array $barsPayload, float $sma20, ?bool $existingVolumeConfirmed): array
    {
        $today = $barsPayload['today'];
        $allBars = $barsPayload['bars'];
        $priorBars = array_slice($allBars, 0, -1);
        $volumeBars = array_slice($priorBars, -20);

        $volumeSma20 = $volumeBars !== []
            ? (int) round(array_sum(array_column($volumeBars, 'volume')) / count($volumeBars))
            : null;

        $adv30Bars = array_slice($priorBars, -30);
        $avgVolume30d = $adv30Bars !== []
            ? (int) round(array_sum(array_column($adv30Bars, 'volume')) / count($adv30Bars))
            : null;

        $isVolumeSignalDay = PolygonDailyBarService::isVolumeSignalDay(
            $today['high'],
            $today['low'],
            $today['close'],
            $sma20,
        );
        $bounceVolumeAboveAverage = $existingVolumeConfirmed ?? false;
        $relativeVolume = null;
        $bounceDayVolume = null;

        if ($isVolumeSignalDay) {
            $bounceDayVolume = (int) round($today['volume']);

            if ($volumeSma20 !== null && $volumeSma20 > 0) {
                $relativeVolume = round($today['volume'] / $volumeSma20, 2);
                $bounceVolumeAboveAverage = $relativeVolume >= self::rvolThreshold();
            }
        }

        return [
            'relative_volume' => $relativeVolume,
            'volume_sma_20' => $volumeSma20,
            'bounce_volume_above_average' => $bounceVolumeAboveAverage,
            'bounce_day_volume' => $bounceDayVolume,
            'avg_volume_30d' => $avgVolume30d,
        ];
    }
}
