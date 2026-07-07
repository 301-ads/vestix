<?php

namespace App\Support;

use App\Services\PolygonDailyBarService;

class RelativeVolumeCalculator
{
    public static function rvolThreshold(): float
    {
        return (float) config('vestix.sniper_scorecard.rvol_threshold', 1.2);
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

        $isBounceDay = PolygonDailyBarService::isBounceDay($today['low'], $today['close'], $sma20);
        $bounceVolumeAboveAverage = $existingVolumeConfirmed ?? false;
        $relativeVolume = null;
        $bounceDayVolume = null;

        if ($isBounceDay) {
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
