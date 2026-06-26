<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use Illuminate\Support\Facades\Cache;

class MarketDataFetcher
{
    public function __construct(
        private AlphaVantageService $alphaVantage,
        private PolygonMarketDataService $polygonMarketData,
        private DailyBarProvider $dailyBars,
    ) {}

    /**
     * @return array{
     *     latest_open_price: float|null,
     *     latest_close_price: float,
     *     recent_close_prices: array<int, float>,
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
    public function fetchForTicker(string $ticker, bool $withDelays = true, ?bool $bounceVolumeAboveAverage = null): ?array
    {
        $payload = $this->polygonMarketData->fetchForTicker($ticker, $bounceVolumeAboveAverage);

        if ($payload === null && config('vestix.alpha_vantage.api_key')) {
            $payload = $this->fetchFromAlphaVantage($ticker, $withDelays, $bounceVolumeAboveAverage);
        }

        if ($payload !== null) {
            $this->touchApiFetchTimestamp();
        }

        return $payload;
    }

    public function syncPosition(Position $position, bool $withDelays = true): bool
    {
        $data = $this->fetchForTicker(
            $position->ticker,
            $withDelays,
            $position->bounce_volume_above_average,
        );

        if ($data === null) {
            return false;
        }

        $position->update($data);

        return true;
    }

    public function backfillRecentClosePrices(Position $position): bool
    {
        if (filled($position->recent_close_prices)) {
            return false;
        }

        $payload = $this->fetchForTicker($position->ticker, withDelays: false);

        if ($payload !== null) {
            $position->update([
                'recent_close_prices' => $payload['recent_close_prices'],
            ]);

            return true;
        }

        $bars = $this->dailyBars->fetchRecentBars($position->ticker, lookbackDays: 30, limit: 20);

        if ($bars === null || count($bars['bars']) < 2) {
            return false;
        }

        $recentClosePrices = PolygonMarketDataService::extractRecentClosePrices($bars['bars']);
        $latestClose = $position->latest_close_price;

        if ($latestClose !== null && $latestClose !== '') {
            $latestClose = round((float) $latestClose, 2);
            $lastStored = round((float) end($recentClosePrices), 2);

            if ($latestClose !== $lastStored) {
                $recentClosePrices[] = $latestClose;
                $recentClosePrices = array_values(array_slice($recentClosePrices, -14));
            }
        }

        $position->update([
            'recent_close_prices' => $recentClosePrices,
        ]);

        return true;
    }

    /**
     * @return array{
     *     latest_open_price: float|null,
     *     latest_close_price: float,
     *     recent_close_prices: array<int, float>,
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
    private function fetchFromAlphaVantage(string $ticker, bool $withDelays, ?bool $bounceVolumeAboveAverage): ?array
    {
        $delay = config('vestix.alpha_vantage.intra_request_delay', 2);

        $globalQuote = $this->alphaVantage->fetchGlobalQuote($ticker);

        if ($withDelays) {
            sleep($delay);
        }

        $close = $globalQuote['close'] ?? null;

        if ($withDelays) {
            sleep($delay);
        }

        $smaPair = $this->alphaVantage->fetchSma20Pair($ticker);
        $sma = $smaPair['latest'];

        if ($withDelays) {
            sleep($delay);
        }

        $sma50 = $this->alphaVantage->fetchSma50($ticker);

        if ($withDelays) {
            sleep($delay);
        }

        $atr = $this->alphaVantage->fetchAtr14($ticker);

        if ($withDelays) {
            sleep($delay);
        }

        $rsi = $this->alphaVantage->fetchRsi14($ticker);

        if ($close === null || $sma === null || $sma50 === null || $atr === null || $rsi === null) {
            return null;
        }

        $bars = $this->dailyBars->fetchRecentBars($ticker);
        $recentClosePrices = $bars !== null
            ? PolygonMarketDataService::extractRecentClosePrices($bars['bars'])
            : [round((float) $close, 2)];

        $payload = [
            'latest_open_price' => $globalQuote['open'] ?? null,
            'latest_close_price' => $close,
            'recent_close_prices' => $recentClosePrices,
            'latest_sma_20' => $sma,
            'sma_20_five_days_ago' => $smaPair['five_days_ago'],
            'latest_sma_50' => $sma50,
            'latest_atr_14' => $atr,
            'scout_rsi' => $rsi,
        ];

        $volumeData = $this->resolveVolumeData($ticker, $sma, $bounceVolumeAboveAverage);

        if ($volumeData !== null) {
            $payload = array_merge($payload, $volumeData);
        }

        return $payload;
    }

    /**
     * @return array{
     *     bounce_volume_above_average: bool,
     *     bounce_day_volume: int|null,
     *     avg_volume_30d: int|null,
     * }|null
     */
    private function resolveVolumeData(string $ticker, float $sma20, ?bool $existingVolumeConfirmed): ?array
    {
        $bars = $this->dailyBars->fetchRecentBars($ticker);

        if ($bars === null) {
            return null;
        }

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

    public function touchApiFetchTimestamp(): void
    {
        Cache::put('vestix:last_api_fetch', now()->toIso8601String(), now()->addDays(30));
    }

    public static function syncLockKey(): string
    {
        return 'vestix:api-sync';
    }
}
