<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Contracts\QuoteProvider;
use App\Support\ScoutSetupScorecard;
use App\Support\SignalCandleResolver;
use App\Support\TechnicalIndicators;
use App\Support\TrampolineDepthMetrics;
use App\Support\UsMarketSession;
use Illuminate\Support\Facades\Log;

class PolygonMarketDataService
{
    private const MIN_BARS = 50;

    public function __construct(
        private DailyBarProvider $dailyBars,
        private QuoteProvider $quoteProvider,
        private SessionVolumeResolver $sessionVolumeResolver,
        private TrampolineDepthMetrics $depthMetrics,
    ) {}

    /**
     * @return array{
     *     latest_open_price: float,
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
        ?bool $bounceVolumeAboveAverage = null,
        ?string $sectorEtfOverride = null,
    ): ?array {
        $bars = $this->dailyBars->fetchRecentBars($ticker, lookbackDays: 90, limit: 120);

        if ($bars === null) {
            return null;
        }

        if (count($bars['bars']) < self::MIN_BARS) {
            Log::warning('Market data insufficient bars for indicators.', [
                'ticker' => $ticker,
                'count' => count($bars['bars']),
                'required' => self::MIN_BARS,
            ]);

            return null;
        }

        $bars = $this->supplementLatestSessionFromQuote($ticker, $bars);

        if ($bars === null) {
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

        $open = $bars['today']['open'];
        $close = $bars['today']['close'];
        $sma20 = TechnicalIndicators::smaAtOffset($closes, 20, 0);
        $sma20FiveDaysAgo = TechnicalIndicators::smaAtOffset($closes, 20, 5);
        $sma20TenDaysAgo = TechnicalIndicators::smaAtOffset($closes, 20, ScoutSetupScorecard::smaSlopeLookbackDays());
        $sma50 = TechnicalIndicators::smaAtOffset($closes, 50, 0);
        $atr = TechnicalIndicators::wilderAtr($ohlcBars, 14);
        $rsi = TechnicalIndicators::wilderRsi($closes, 14);

        if ($sma20 === null || $sma50 === null || $atr === null || $rsi === null) {
            return null;
        }

        $payload = [
            'latest_open_price' => $open,
            'latest_close_price' => $close,
            'recent_close_prices' => self::extractRecentClosePrices($bars['bars']),
            'latest_sma_20' => $sma20,
            'sma_20_five_days_ago' => $sma20FiveDaysAgo,
            'sma_20_ten_days_ago' => $sma20TenDaysAgo,
            'latest_sma_50' => $sma50,
            'latest_atr_14' => $atr,
            'scout_rsi' => $rsi,
            'prior_day_low' => self::extractPriorDayLow($bars['bars']),
        ];

        $volumeData = $this->resolveDepthMetrics(
            $ticker,
            $bars,
            $sma20,
            $atr,
            $bounceVolumeAboveAverage,
            $sectorEtfOverride,
        );

        if ($volumeData !== null) {
            $payload = array_merge($payload, $volumeData);
        }

        $signalBars = SignalCandleResolver::resolveFromBars($bars['bars']);
        $payload['latest_bounce_bar'] = $signalBars['latest_bounce_bar'];
        $payload['latest_rejection_bar'] = $signalBars['latest_rejection_bar'];

        return $payload;
    }

    /**
     * @param  array<int, array{close: float}>  $bars
     * @return array<int, float>
     */
    public static function extractRecentClosePrices(array $bars, int $limit = 14): array
    {
        $closes = array_column($bars, 'close');
        $recent = array_slice($closes, -$limit);

        return array_values(array_map(
            static fn (mixed $close): float => round((float) $close, 2),
            $recent,
        ));
    }

    /**
     * @param  array<int, array{low: float}>  $bars
     */
    public static function extractPriorDayLow(array $bars): ?float
    {
        if (count($bars) < 2) {
            return null;
        }

        $priorBar = $bars[count($bars) - 2];

        return round((float) $priorBar['low'], 2);
    }

    /**
     * Daily bars from Polygon Basic often lag behind the latest session.
     * After US market close we refresh that session via the quote provider chain
     * (Finnhub → Alpha Vantage → Polygon).
     *
     * @param  array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }  $barsPayload
     * @return array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }|null
     */
    private function supplementLatestSessionFromQuote(string $ticker, array $barsPayload): ?array
    {
        $lastBar = $barsPayload['bars'][array_key_last($barsPayload['bars'])];

        if (! UsMarketSession::needsLatestSessionQuote($lastBar['date'])) {
            return $barsPayload;
        }

        $quote = $this->quoteProvider->fetchSessionQuote($ticker);

        if ($quote === null || ! isset($quote['close'])) {
            Log::warning('Latest session quote refresh failed: no quote provider available.', [
                'ticker' => $ticker,
                'polygon_bar_date' => $lastBar['date'],
            ]);

            return $barsPayload;
        }

        $sessionDate = UsMarketSession::expectedLastCompletedSessionDate()->toDateString();
        $bars = $barsPayload['bars'];

        if ($bars !== [] && end($bars)['date'] === $sessionDate) {
            array_pop($bars);
        }

        $close = (float) $quote['close'];
        $high = (float) ($quote['high'] ?? $close);
        $low = (float) ($quote['low'] ?? $close);
        $previousClose = $bars !== [] ? (float) $bars[array_key_last($bars)]['close'] : $close;
        $open = (float) ($quote['open'] ?? $previousClose);

        $sessionVolume = $this->sessionVolumeResolver->resolve($ticker, $sessionDate)
            ?? (float) $barsPayload['today']['volume'];

        $bars[] = [
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $sessionVolume,
            'date' => $sessionDate,
        ];

        $priorBars = array_slice($bars, 0, -1);
        $advBars = array_slice($priorBars, -30);
        $adv30 = $advBars === []
            ? $barsPayload['adv30']
            : array_sum(array_column($advBars, 'volume')) / count($advBars);

        $today = $bars[array_key_last($bars)];

        Log::info('Latest session refreshed from quote provider.', [
            'ticker' => $ticker,
            'provider' => $quote['provider'] ?? 'unknown',
            'session_date' => $sessionDate,
            'bar_date_before_refresh' => $lastBar['date'],
            'bar_close_before_refresh' => $lastBar['close'],
            'refreshed_close' => $close,
            'refreshed_volume' => $sessionVolume,
            'volume_source' => $sessionVolume === (float) $barsPayload['today']['volume'] ? 'polygon_fallback' : 'session_resolver',
            'after_market_close' => UsMarketSession::isAfterMarketClose(),
        ]);

        return [
            'today' => [
                'open' => $today['open'],
                'high' => $today['high'],
                'low' => $today['low'],
                'close' => $today['close'],
                'volume' => $today['volume'],
            ],
            'adv30' => $adv30,
            'bars' => $bars,
        ];
    }

    /**
     * @param  array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }  $bars
     * @return array<string, mixed>|null
     */
    private function resolveDepthMetrics(
        string $ticker,
        array $bars,
        float $sma20,
        float $atr,
        ?bool $existingVolumeConfirmed,
        ?string $sectorEtfOverride,
    ): ?array {
        return $this->depthMetrics->resolve(
            $ticker,
            $bars,
            $sma20,
            $atr,
            $existingVolumeConfirmed,
            $sectorEtfOverride,
        );
    }
}
