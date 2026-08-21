<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Contracts\QuoteProvider;
use App\Models\Position;
use App\Support\MarketDataFreshness;
use App\Support\ScoutSetupScorecard;
use App\Support\SignalCandleResolver;
use App\Support\TechnicalIndicators;
use App\Support\TrampolineDepthMetrics;
use App\Support\UsMarketSession;
use Illuminate\Support\Facades\Cache;

class MarketDataFetcher
{
    public function __construct(
        private AlphaVantageService $alphaVantage,
        private PolygonMarketDataService $polygonMarketData,
        private DailyBarProvider $dailyBars,
        private TrampolineDepthMetrics $depthMetrics,
        private QuoteProvider $quotes,
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
     *     latest_session_bar?: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
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
        $sessionBar = $data['latest_session_bar'] ?? null;
        $dailyBars = is_array($data['daily_bars'] ?? null) ? $data['daily_bars'] : [];
        unset($data['latest_bounce_bar'], $data['latest_rejection_bar'], $data['latest_session_bar'], $data['daily_bars']);

        if ($position->status === 'scout') {
            $patternBar = $position->isShort()
                ? (is_array($rejectionBar) ? $rejectionBar : null)
                : (is_array($bounceBar) ? $bounceBar : null);
            $incomingClose = isset($data['latest_close_price'])
                ? (float) $data['latest_close_price']
                : null;
            $throughMarket = $position->isPlannedEntryThroughMarket($incomingClose);
            $signalBar = ($throughMarket && is_array($sessionBar))
                ? $sessionBar
                : $patternBar;

            if ($patternBar !== null) {
                $data['detected_signal_bar_date'] = $patternBar['date'];
            }

            if ($this->shouldApplySignalCandle($position, $signalBar, $forceSignalRefresh, $data)) {
                $signalAttributes = $this->buildSignalCandleAttributes(
                    $position,
                    $signalBar,
                    isset($data['latest_atr_14']) ? (float) $data['latest_atr_14'] : null,
                );

                if ($signalAttributes !== null) {
                    $data = array_merge($data, $signalAttributes);
                }
            }

            $signalDate = isset($data['signal_bar_date'])
                ? (string) $data['signal_bar_date']
                : $position->signal_bar_date?->toDateString();
            $extremes = SignalCandleResolver::extremesSince($dailyBars, $signalDate);

            if ($extremes !== null) {
                $data['post_signal_high'] = $extremes['high'];
                $data['post_signal_low'] = $extremes['low'];
            }
        }

        // Open P&L should track live/EH marks — EOD bars alone can lag a session and
        // falsely trigger STOPPED OUT (e.g. BEN 32.58 vs live 33.97).
        if ($position->status === 'open') {
            $polygonClose = isset($data['latest_close_price'])
                ? (float) $data['latest_close_price']
                : null;
            $existingClose = $position->latest_close_price !== null
                ? (float) $position->latest_close_price
                : null;

            $liveMark = $this->resolveOpenPositionLiveMark(
                $position->ticker,
                $polygonClose,
            );

            if ($liveMark !== null) {
                $data['latest_close_price'] = $liveMark;
            } elseif ($existingClose !== null) {
                // Live overlay unavailable: keep the stored mark instead of a lagging daily bar.
                $data['latest_close_price'] = $existingClose;
            }
        }

        $position->update($data);

        if ($position->status === 'scout') {
            $position->refresh();
            $position->syncAdvisedEntryFromSignal();
        }

        return true;
    }

    /**
     * Copy shared indicator/mark fields from a synced row onto every other tracked
     * position with the same ticker (other users benefit without a second Polygon call).
     *
     * latest_close_price is status-specific: open rows use a live/Yahoo mark for P&L,
     * scouts keep the Polygon session close for signals/scorecards. Bulk unique prefers
     * an open representative, but a user-scoped scout sync can still be the source —
     * never copy a scout EOD onto opens; overlay live marks on open siblings instead.
     */
    public function propagateSharedMarketData(Position $source): void
    {
        $ticker = strtoupper(trim($source->ticker));

        if ($ticker === '') {
            return;
        }

        $shared = array_filter([
            'latest_open_price' => $source->latest_open_price,
            'recent_close_prices' => $source->recent_close_prices,
            'latest_sma_20' => $source->latest_sma_20,
            'sma_20_five_days_ago' => $source->sma_20_five_days_ago,
            'sma_20_ten_days_ago' => $source->sma_20_ten_days_ago,
            'latest_sma_50' => $source->latest_sma_50,
            'latest_sma_200' => $source->latest_sma_200,
            'latest_atr_14' => $source->latest_atr_14,
            'scout_rsi' => $source->scout_rsi,
            'prior_day_low' => $source->prior_day_low,
            'bounce_volume_above_average' => $source->bounce_volume_above_average,
            'bounce_day_volume' => $source->bounce_day_volume,
            'avg_volume_30d' => $source->avg_volume_30d,
            'relative_volume' => $source->relative_volume,
            'volume_sma_20' => $source->volume_sma_20,
            'sector_etf' => $source->sector_etf,
            'sector_close' => $source->sector_close,
            'sector_sma_50' => $source->sector_sma_50,
            'sector_trend_positive' => $source->sector_trend_positive,
            'pre_bounce_extension_atr' => $source->pre_bounce_extension_atr,
        ], static fn (mixed $value): bool => $value !== null);

        if ($shared !== []) {
            if (isset($shared['recent_close_prices']) && is_array($shared['recent_close_prices'])) {
                $shared['recent_close_prices'] = json_encode($shared['recent_close_prices']);
            }

            Position::query()
                ->tracked()
                ->where('ticker', $ticker)
                ->whereKeyNot($source->id)
                ->update($shared);
        }

        if ($source->latest_close_price !== null) {
            Position::query()
                ->tracked()
                ->where('ticker', $ticker)
                ->where('status', $source->status)
                ->whereKeyNot($source->id)
                ->update(['latest_close_price' => $source->latest_close_price]);
        }

        if ($source->status === 'open') {
            $sessionClose = $this->sessionCloseFromRecentPrices($source);

            if ($sessionClose !== null) {
                Position::query()
                    ->scout()
                    ->where('ticker', $ticker)
                    ->whereKeyNot($source->id)
                    ->update(['latest_close_price' => $sessionClose]);
            }
        } else {
            $openSibling = Position::query()
                ->open()
                ->where('ticker', $ticker)
                ->first();

            if ($openSibling !== null) {
                $this->refreshOpenPositionLiveMark($openSibling);
            }
        }
    }

    /**
     * Polygon EOD close from the shared bar series (not the live overlay on open rows).
     */
    private function sessionCloseFromRecentPrices(Position $source): ?float
    {
        $recent = $source->recent_close_prices;

        if (! is_array($recent) || $recent === []) {
            return null;
        }

        $last = end($recent);

        return is_numeric($last) ? round((float) $last, 2) : null;
    }

    /**
     * Lightweight mark refresh for open P&L / Actuele Koers (no SMA/ATR round-trip).
     * Used by Sync (immediate UI) and edit-page mount so stale delayed last-trades get repaired.
     */
    public function refreshOpenPositionLiveMark(Position $position, bool $force = false): ?float
    {
        if ($position->status !== 'open') {
            return null;
        }

        $ticker = strtoupper(trim($position->ticker));
        $cacheKey = 'vestix:open_live_mark:'.$ticker;

        if (! $force) {
            $cached = Cache::get($cacheKey);

            if (is_numeric($cached)) {
                $rounded = round((float) $cached, 2);

                if ($position->latest_close_price === null
                    || abs((float) $position->latest_close_price - $rounded) >= 0.01) {
                    Position::query()
                        ->open()
                        ->where('ticker', $ticker)
                        ->update(['latest_close_price' => $rounded]);

                    $position->refresh();
                }

                return $rounded;
            }
        }

        $sessionClose = $position->latest_close_price !== null
            ? (float) $position->latest_close_price
            : null;

        $live = UsMarketSession::isPremarketWindow()
            ? ($this->quotes->fetchPremarketPrice($ticker, $sessionClose)
                ?? $this->quotes->fetchLivePrice($ticker))
            : $this->quotes->fetchLivePrice($ticker);

        if ($live === null) {
            return null;
        }

        $rounded = round($live, 2);

        Position::query()
            ->open()
            ->where('ticker', $ticker)
            ->update(['latest_close_price' => $rounded]);

        Cache::put($cacheKey, $rounded, now()->addMinutes(2));
        MarketDataFreshness::markIntradayQuoteFetch();
        $position->refresh();

        return $rounded;
    }

    private function resolveOpenPositionLiveMark(string $ticker, ?float $sessionClose): ?float
    {
        if (UsMarketSession::isPremarketWindow()) {
            $live = $this->quotes->fetchPremarketPrice($ticker, $sessionClose)
                ?? $this->quotes->fetchLivePrice($ticker);

            return $live !== null ? round($live, 2) : null;
        }

        // RTH watch window and overnight/after-close: prefer Yahoo/session mark so a
        // lagging Polygon daily bar cannot overwrite the real last close / tape.
        $live = $this->quotes->fetchLivePrice($ticker);

        return $live !== null ? round($live, 2) : null;
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
     * @param  array<string, mixed>  $incoming
     */
    private function shouldApplySignalCandle(
        Position $position,
        ?array $signalBar,
        bool $forceSignalRefresh,
        array $incoming = [],
    ): bool {
        if ($signalBar === null) {
            return false;
        }

        if ($forceSignalRefresh) {
            return true;
        }

        $incomingClose = isset($incoming['latest_close_price'])
            ? (float) $incoming['latest_close_price']
            : null;

        // Consumed stop: always take the candidate bar (session high/low, not a stale bounce).
        if ($position->isPlannedEntryThroughMarket($incomingClose)) {
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
     *     latest_session_bar?: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
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
            'latest_sma_200' => $bars !== null
                ? TechnicalIndicators::smaAtOffset(array_column($bars['bars'], 'close'), 200, 0)
                : null,
            'latest_atr_14' => $atr,
            'scout_rsi' => $rsi,
            'prior_day_low' => $priorDayLow,
            'latest_session_bar' => $bars !== null
                ? PolygonMarketDataService::extractLatestSessionBar($bars['bars'])
                : null,
            'daily_bars' => $bars['bars'] ?? [],
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
            $payload['latest_session_bar'] = PolygonMarketDataService::extractLatestSessionBar($bars['bars']);
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
