<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Support\ScoutSetupScorecard;
use App\Support\SignalCandleResolver;
use App\Support\TechnicalIndicators;
use App\Support\TrampolineDepthMetrics;
use Illuminate\Support\Facades\Cache;

class MarketDataFetcher
{
    public function __construct(
        private AlphaVantageService $alphaVantage,
        private PolygonMarketDataService $polygonMarketData,
        private DailyBarProvider $dailyBars,
        private TrampolineDepthMetrics $depthMetrics,
    ) {}

    /**
     * @return array{
     *     latest_open_price: float|null,
     *     latest_close_price: float,
     *     recent_close_prices: array<int, float>,
     *     latest_sma_20: float,
     *     sma_20_five_days_ago: float|null,
     *     sma_20_ten_days_ago: float|null,
     *     latest_sma_50: float,
     *     latest_atr_14: float,
     *     scout_rsi: float,
     *     prior_day_low: float|null,
     *     bounce_volume_above_average?: bool,
     *     bounce_day_volume?: int|null,
     *     avg_volume_30d?: int|null,
     *     relative_volume?: float|null,
     *     volume_sma_20?: int|null,
     *     sector_etf?: string|null,
     *     sector_close?: float|null,
     *     sector_sma_50?: float|null,
     *     sector_trend_positive?: bool,
     *     pre_bounce_extension_atr?: float|null,
     *     latest_bounce_bar?: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
     *     latest_rejection_bar?: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
     * }|null
     */
    public function fetchForTicker(
        string $ticker,
        bool $withDelays = true,
        ?bool $bounceVolumeAboveAverage = null,
        ?string $sectorEtfOverride = null,
    ): ?array {
        $payload = $this->polygonMarketData->fetchForTicker(
            $ticker,
            $bounceVolumeAboveAverage,
            $sectorEtfOverride,
        );

        if ($payload === null && config('vestix.alpha_vantage.api_key')) {
            $payload = $this->fetchFromAlphaVantage(
                $ticker,
                $withDelays,
                $bounceVolumeAboveAverage,
                $sectorEtfOverride,
            );
        }

        if ($payload !== null) {
            $this->touchApiFetchTimestamp();
        }

        return $payload;
    }

    public function syncPosition(
        Position $position,
        bool $withDelays = true,
        bool $forceSignalRefresh = false,
    ): bool {
        $data = $this->fetchForTicker(
            $position->ticker,
            $withDelays,
            $position->bounce_volume_above_average,
            $position->sector_etf_override,
        );

        if ($data === null) {
            return false;
        }

        $bounceBar = $data['latest_bounce_bar'] ?? null;
        $rejectionBar = $data['latest_rejection_bar'] ?? null;
        unset($data['latest_bounce_bar'], $data['latest_rejection_bar']);

        if ($position->status === 'scout') {
            $signalBar = $position->isShort()
                ? (is_array($rejectionBar) ? $rejectionBar : null)
                : (is_array($bounceBar) ? $bounceBar : null);

            if ($signalBar !== null) {
                $data['detected_signal_bar_date'] = $signalBar['date'];
            }

            if ($this->shouldApplySignalCandle($position, $signalBar, $forceSignalRefresh)) {
                $signalAttributes = $this->buildSignalCandleAttributes(
                    $position,
                    $signalBar,
                    isset($data['latest_atr_14']) ? (float) $data['latest_atr_14'] : null,
                );

                if ($signalAttributes !== null) {
                    $data = array_merge($data, $signalAttributes);
                }
            }
        }

        $position->update($data);

        return true;
    }

    /**
     * Force-apply the latest bounce/rejection candle onto a scout (Order Plan override).
     */
    public function refreshSignalCandle(Position $position): bool
    {
        if ($position->status !== 'scout') {
            return false;
        }

        return $this->syncPosition($position, withDelays: false, forceSignalRefresh: true);
    }

    /**
     * @param  array{date: string, open: float, high: float, low: float, close: float, volume: float}|null  $signalBar
     */
    private function shouldApplySignalCandle(
        Position $position,
        ?array $signalBar,
        bool $forceSignalRefresh,
    ): bool {
        if ($signalBar === null) {
            return false;
        }

        if ($forceSignalRefresh) {
            return true;
        }

        if ($position->isSignalCandleAutoRefreshLocked()) {
            return false;
        }

        if ($position->signal_bar_date === null) {
            return $position->signal_low === null && $position->signal_high === null;
        }

        return $signalBar['date'] > $position->signal_bar_date->toDateString();
    }

    /**
     * @param  array{date: string, open: float, high: float, low: float, close: float, volume: float}  $signalBar
     * @return array{signal_low: float, signal_high: float, signal_bar_date: string, entry_price?: float}|null
     */
    private function buildSignalCandleAttributes(
        Position $position,
        array $signalBar,
        ?float $atr,
    ): ?array {
        $atr ??= $position->latest_atr_14 !== null ? (float) $position->latest_atr_14 : null;

        $attributes = [
            'signal_low' => round((float) $signalBar['low'], 2),
            'signal_high' => round((float) $signalBar['high'], 2),
            'signal_bar_date' => $signalBar['date'],
        ];

        $entry = $position->isShort()
            ? Position::computeSellStop($attributes['signal_low'], $atr)
            : Position::computeBuyStop($attributes['signal_high'], $atr);

        if ($entry !== null) {
            $attributes['entry_price'] = $entry;
        }

        return $attributes;
    }

    public function backfillRecentClosePrices(Position $position): bool
    {
        if (filled($position->recent_close_prices)) {
            return false;
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
     *     sma_20_ten_days_ago: float|null,
     *     latest_sma_50: float,
     *     latest_atr_14: float,
     *     scout_rsi: float,
     *     prior_day_low: float|null,
     *     bounce_volume_above_average?: bool,
     *     bounce_day_volume?: int|null,
     *     avg_volume_30d?: int|null,
     *     latest_bounce_bar?: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
     *     latest_rejection_bar?: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
     * }|null
     */
    private function fetchFromAlphaVantage(
        string $ticker,
        bool $withDelays,
        ?bool $bounceVolumeAboveAverage,
        ?string $sectorEtfOverride,
    ): ?array {
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
        $priorDayLow = $bars !== null
            ? PolygonMarketDataService::extractPriorDayLow($bars['bars'])
            : null;

        $payload = [
            'latest_open_price' => $globalQuote['open'] ?? null,
            'latest_close_price' => $close,
            'recent_close_prices' => $recentClosePrices,
            'latest_sma_20' => $sma,
            'sma_20_five_days_ago' => $smaPair['five_days_ago'],
            'sma_20_ten_days_ago' => $bars !== null
                ? TechnicalIndicators::smaAtOffset(
                    array_column($bars['bars'], 'close'),
                    20,
                    ScoutSetupScorecard::smaSlopeLookbackDays(),
                )
                : null,
            'latest_sma_50' => $sma50,
            'latest_atr_14' => $atr,
            'scout_rsi' => $rsi,
            'prior_day_low' => $priorDayLow,
        ];

        $volumeData = $this->resolveDepthMetrics(
            $ticker,
            $bars,
            (float) $sma,
            (float) $atr,
            $bounceVolumeAboveAverage,
            $sectorEtfOverride,
        );

        if ($volumeData !== null) {
            $payload = array_merge($payload, $volumeData);
        }

        if ($bars !== null) {
            $signalBars = SignalCandleResolver::resolveFromBars($bars['bars']);
            $payload['latest_bounce_bar'] = $signalBars['latest_bounce_bar'];
            $payload['latest_rejection_bar'] = $signalBars['latest_rejection_bar'];
        }

        return $payload;
    }

    /**
     * @param  array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }|null  $bars
     * @return array<string, mixed>|null
     */
    private function resolveDepthMetrics(
        string $ticker,
        ?array $bars,
        float $sma20,
        float $atr,
        ?bool $existingVolumeConfirmed,
        ?string $sectorEtfOverride,
    ): ?array {
        if ($bars === null) {
            return null;
        }

        return $this->depthMetrics->resolve(
            $ticker,
            $bars,
            $sma20,
            $atr,
            $existingVolumeConfirmed,
            $sectorEtfOverride,
        );
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
