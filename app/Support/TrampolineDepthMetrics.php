<?php

namespace App\Support;

class TrampolineDepthMetrics
{
    public function __construct(
        private SectorTrendResolver $sectorTrendResolver,
    ) {}

    /**
     * @param  array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }  $barsPayload
     * @return array{
     *     relative_volume: float|null,
     *     volume_sma_20: int|null,
     *     bounce_volume_above_average: bool,
     *     bounce_day_volume: int|null,
     *     avg_volume_30d: int|null,
     *     sector_etf: string|null,
     *     sector_close: float|null,
     *     sector_sma_50: float|null,
     *     sector_trend_positive: bool,
     *     pre_bounce_extension_atr: float|null,
     * }
     */
    public function resolve(
        string $ticker,
        array $barsPayload,
        float $sma20,
        float $atr14,
        ?bool $existingVolumeConfirmed,
        ?string $sectorEtfOverride = null,
    ): array {
        $volume = RelativeVolumeCalculator::resolve($barsPayload, $sma20, $existingVolumeConfirmed);
        $sector = $this->sectorTrendResolver->resolve($ticker, $sectorEtfOverride);
        $extension = PreBounceExtensionCalculator::calculate($barsPayload['bars'], $sma20, $atr14);

        return [
            ...$volume,
            'sector_etf' => $sector['sector_etf'] ?? null,
            'sector_close' => $sector['sector_close'] ?? null,
            'sector_sma_50' => $sector['sector_sma_50'] ?? null,
            'sector_trend_positive' => (bool) ($sector['sector_trend_positive'] ?? false),
            'pre_bounce_extension_atr' => $extension,
        ];
    }
}
