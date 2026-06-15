<?php

namespace App\Services;

use App\Support\TechnicalIndicators;

class PolygonMarketDataService
{
    private const MIN_BARS = 55;

    public function __construct(
        private PolygonDailyBarService $polygonDailyBars,
    ) {}

    /**
     * @return array{
     *     latest_close_price: float,
     *     latest_sma_20: float,
     *     sma_20_five_days_ago: float|null,
     *     latest_sma_50: float,
     *     latest_atr_14: float,
     *     scout_rsi: float,
     *     bounce_volume_above_average?: bool,
     *     bounce_day_volume?: int|null,
     *     avg_volume_30d?: int|null,
     * }|null
     */
    public function fetchForTicker(string $ticker, ?bool $bounceVolumeAboveAverage = null): ?array
    {
        $bars = $this->polygonDailyBars->fetchRecentBars($ticker, lookbackDays: 70, limit: 120);

        if ($bars === null || count($bars['bars']) < self::MIN_BARS) {
            return null;
        }

        $closes = array_column($bars['bars'], 'close');
        $ohlcBars = array_map(
            static fn (array $bar): array => [
                'high' => $bar['high'],
                'low' => $bar['low'],
                'close' => $bar['close'],
            ],
            $bars['bars'],
        );

        $close = $bars['today']['close'];
        $sma20 = TechnicalIndicators::smaAtOffset($closes, 20, 0);
        $sma20FiveDaysAgo = TechnicalIndicators::smaAtOffset($closes, 20, 5);
        $sma50 = TechnicalIndicators::smaAtOffset($closes, 50, 0);
        $atr = TechnicalIndicators::wilderAtr($ohlcBars, 14);
        $rsi = TechnicalIndicators::wilderRsi($closes, 14);

        if ($sma20 === null || $sma50 === null || $atr === null || $rsi === null) {
            return null;
        }

        $payload = [
            'latest_close_price' => $close,
            'latest_sma_20' => $sma20,
            'sma_20_five_days_ago' => $sma20FiveDaysAgo,
            'latest_sma_50' => $sma50,
            'latest_atr_14' => $atr,
            'scout_rsi' => $rsi,
        ];

        $volumeData = $this->resolveVolumeData($bars, $sma20, $bounceVolumeAboveAverage);

        if ($volumeData !== null) {
            $payload = array_merge($payload, $volumeData);
        }

        return $payload;
    }

    /**
     * @param  array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }  $bars
     * @return array{
     *     bounce_volume_above_average: bool,
     *     bounce_day_volume: int|null,
     *     avg_volume_30d: int|null,
     * }|null
     */
    private function resolveVolumeData(array $bars, float $sma20, ?bool $existingVolumeConfirmed): ?array
    {
        $today = $bars['today'];
        $isBounceDay = PolygonDailyBarService::isBounceDay($today['low'], $today['close'], $sma20);

        $bounceVolumeAboveAverage = $existingVolumeConfirmed ?? false;

        if ($isBounceDay) {
            $bounceVolumeAboveAverage = $today['volume'] > $bars['adv30'];
        }

        $payload = [
            'bounce_volume_above_average' => $bounceVolumeAboveAverage,
            'avg_volume_30d' => (int) round($bars['adv30']),
        ];

        if ($isBounceDay) {
            $payload['bounce_day_volume'] = (int) round($today['volume']);
        }

        return $payload;
    }
}
