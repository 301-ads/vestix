<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Contracts\QuoteProvider;
use App\Support\TechnicalIndicators;
use App\Support\UsMarketSession;
use Illuminate\Support\Facades\Log;

class PolygonMarketDataService
{
    private const MIN_BARS = 50;

    public function __construct(
        private DailyBarProvider $dailyBars,
        private QuoteProvider $quoteProvider,
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

        $bars[] = [
            'open' => $previousClose,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $barsPayload['today']['volume'],
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
