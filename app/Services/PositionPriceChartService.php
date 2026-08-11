<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Enums\PremarketScanResult;
use App\Models\Position;
use App\Models\SniperDailyBar;
use App\Support\PremarketGatekeeperDisplay;
use App\Support\TechnicalIndicators;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PositionPriceChartService
{
    public const RANGES = ['1D', '1W', '1M', '3M', '6M', '1Y'];

    /** Approximate trading-day windows per range key (daily ranges only). */
    private const RANGE_BARS = [
        '1W' => 5,
        '1M' => 22,
        '3M' => 66,
        '6M' => 132,
        '1Y' => 252,
    ];

    public function __construct(
        private DailyBarProvider $dailyBars,
        private YahooFinanceChartQuoteService $yahoo,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(Position $position, string $range = '3M'): ?array
    {
        $range = $this->normalizeRange($range);

        if ($range === '1D') {
            return $this->buildIntraday($position);
        }

        $barCount = self::RANGE_BARS[$range];
        // +40 covers SMA(20)/RSI(14) warmup before the visible window.
        $fetchLimit = max($barCount + 40, 100);

        $bars = $this->resolveBars($position->ticker, $fetchLimit);
        $demo = false;

        if ($bars === null || count($bars) < 2) {
            $bars = $this->demoBars($position, $barCount + 40);
            $demo = true;
        }

        $window = array_slice($bars, -$barCount);
        if (count($window) < 2) {
            return null;
        }

        $points = [];
        $candles = [];
        foreach ($window as $bar) {
            $close = round((float) $bar['close'], 4);
            $points[] = [
                'time' => $bar['date'],
                'value' => $close,
            ];
            $candles[] = [
                'time' => $bar['date'],
                'open' => round((float) $bar['open'], 4),
                'high' => round((float) $bar['high'], 4),
                'low' => round((float) $bar['low'], 4),
                'close' => $close,
            ];
        }

        return $this->finalizePayload(
            $position,
            $range,
            $points,
            $bars,
            $candles,
            intraday: false,
            demo: $demo,
        );
    }

    public function normalizeRange(string $range): string
    {
        $range = strtoupper(trim($range));

        // UI label "1J" maps to API "1Y".
        if ($range === '1J') {
            $range = '1Y';
        }

        return in_array($range, self::RANGES, true) ? $range : '3M';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildIntraday(Position $position): ?array
    {
        $ticker = strtoupper(trim($position->ticker));
        $cacheKey = "vestix:price-chart-intraday:{$ticker}:5m";

        /** @var list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>|null $bars */
        $bars = Cache::remember($cacheKey, now()->addMinutes(3), function () use ($ticker) {
            return $this->yahoo->fetchIntradayBars($ticker, interval: '5m', range: '1d', includePrePost: true);
        });

        $demo = false;

        if ($bars === null || count($bars) < 2) {
            $bars = $this->demoIntradayBars($position);
            $demo = true;
        }

        $points = [];
        $candles = [];
        foreach ($bars as $bar) {
            $close = round((float) $bar['close'], 4);
            $points[] = [
                'time' => (int) $bar['time'],
                'value' => $close,
            ];
            $candles[] = [
                'time' => (int) $bar['time'],
                'open' => round((float) $bar['open'], 4),
                'high' => round((float) $bar['high'], 4),
                'low' => round((float) $bar['low'], 4),
                'close' => $close,
            ];
        }

        return $this->finalizePayload(
            $position,
            '1D',
            $points,
            $bars,
            $candles,
            intraday: true,
            demo: $demo,
        );
    }

    /**
     * @param  list<array{time: int|string, value: float}>  $points
     * @param  list<array<string, mixed>>  $bars
     * @param  list<array{time: int|string, open: float, high: float, low: float, close: float}>  $candles
     * @return array<string, mixed>|null
     */
    private function finalizePayload(
        Position $position,
        string $range,
        array $points,
        array $bars,
        array $candles,
        bool $intraday,
        bool $demo,
    ): ?array {
        if (count($points) < 2) {
            return null;
        }

        $isScout = $position->status === 'scout';
        $first = (float) $points[0]['value'];
        $last = (float) $points[array_key_last($points)]['value'];
        $absolute = round($last - $first, 4);
        $percent = $first != 0.0 ? round(($absolute / $first) * 100, 2) : 0.0;

        $short = $position->isShort();
        $entryPrice = $position->entry_price !== null ? (float) $position->entry_price : null;
        $entryTime = $intraday
            ? $this->resolveIntradayEntryTime($bars, $position)
            : $this->resolveEntryTime($bars, $position);

        $windowStart = $points[0]['time'];
        $windowEnd = $points[array_key_last($points)]['time'];
        $markers = [];

        // Scout: only price lines (entry is still a plan, not a fill). Open: entry fill marker.
        if (
            ! $isScout
            && $entryTime !== null
            && $entryPrice !== null
            && $entryTime >= $windowStart
            && $entryTime <= $windowEnd
        ) {
            $markers[] = [
                'time' => $entryTime,
                'role' => 'entry',
                'color' => $short ? '#ef4444' : '#22c55e',
                'value' => $entryPrice,
            ];
        }

        $levels = [
            'entry' => $entryPrice,
            'stop' => $this->resolveStopLevel($position),
            'target1' => $position->plannedBracketTarget1Price(),
        ];

        $payload = [
            'ticker' => $position->ticker,
            'range' => $range,
            'intraday' => $intraday,
            'series' => $isScout ? 'candles' : 'area',
            'points' => $points,
            'period_change' => [
                'absolute' => $absolute,
                'percent' => $percent,
                'positive' => $absolute >= 0,
            ],
            'levels' => $levels,
            'markers' => $markers,
            'entry_time' => $entryTime,
            'short' => $short,
            'demo' => $demo,
        ];

        if ($isScout) {
            $payload['candles'] = $candles;
            $payload['premarket'] = $this->resolvePremarketMeta($position);

            // Daily sniper context only — 5m SMA/RSI is not the setup SMA.
            if (! $intraday) {
                $indicators = $this->buildDailyIndicatorSeries($bars, $candles);
                $payload['sma20'] = $indicators['sma20'];
                $payload['rsi14'] = $indicators['rsi14'];
            } else {
                $payload['sma20'] = [];
                $payload['rsi14'] = [];
            }
        }

        return $payload;
    }

    /**
     * Historical SMA(20) / RSI(14) for the visible candle window, with warmup bars.
     *
     * @param  list<array{open?: float, high?: float, low?: float, close: float|int, date?: string, time?: int}>  $allBars
     * @param  list<array{time: int|string, open: float, high: float, low: float, close: float}>  $visibleCandles
     * @return array{sma20: list<array{time: int|string, value: float}>, rsi14: list<array{time: int|string, value: float}>}
     */
    private function buildDailyIndicatorSeries(array $allBars, array $visibleCandles): array
    {
        $sma20 = [];
        $rsi14 = [];

        if ($allBars === [] || $visibleCandles === []) {
            return ['sma20' => $sma20, 'rsi14' => $rsi14];
        }

        $visibleTimes = [];
        foreach ($visibleCandles as $candle) {
            $visibleTimes[(string) $candle['time']] = true;
        }

        $warmup = 30;
        $displayStart = max(0, count($allBars) - count($visibleCandles));
        $displayEnd = count($allBars) - 1;
        $calcStart = max(0, $displayStart - $warmup);

        $closes = [];
        for ($i = $calcStart; $i <= $displayEnd; $i++) {
            $closes[] = (float) $allBars[$i]['close'];
        }

        for ($i = $displayStart; $i <= $displayEnd; $i++) {
            $bar = $allBars[$i];
            $time = $bar['date'] ?? $bar['time'] ?? null;

            if ($time === null || ! isset($visibleTimes[(string) $time])) {
                continue;
            }

            $closeOffset = $i - $calcStart;
            $slice = array_slice($closes, 0, $closeOffset + 1);

            $sma = TechnicalIndicators::sma($slice, 20);
            if ($sma !== null) {
                $sma20[] = ['time' => $time, 'value' => round($sma, 4)];
            }

            $rsi = TechnicalIndicators::wilderRsi($slice, 14);
            if ($rsi !== null) {
                $rsi14[] = ['time' => $time, 'value' => round($rsi, 2)];
            }
        }

        return ['sma20' => $sma20, 'rsi14' => $rsi14];
    }

    private function resolveStopLevel(Position $position): ?float
    {
        if ($position->status === 'scout') {
            if ($position->new_sl !== null) {
                return (float) $position->new_sl;
            }
        }

        if ($position->current_sl !== null) {
            return (float) $position->current_sl;
        }

        return $position->initial_sl !== null ? (float) $position->initial_sl : null;
    }

    /**
     * @return array{
     *     price: float|null,
     *     label: string|null,
     *     description: string|null,
     *     tone: string,
     *     checked: bool
     * }
     */
    private function resolvePremarketMeta(Position $position): array
    {
        $card = PremarketGatekeeperDisplay::cockpitCardData($position);

        if ($card === null) {
            return [
                'price' => $position->premarket_price !== null
                    ? (float) $position->premarket_price
                    : null,
                'label' => null,
                'description' => PremarketGatekeeperDisplay::isRelevant($position)
                    ? null
                    : 'Nog geen pre-market check vandaag.',
                'tone' => 'gray',
                'checked' => PremarketGatekeeperDisplay::isRelevant($position),
            ];
        }

        $price = $position->premarket_price !== null
            ? (float) $position->premarket_price
            : null;

        $tone = match ($card['descriptionColor'] ?? 'gray') {
            'danger' => 'danger',
            'success' => 'success',
            'warning' => 'warning',
            default => 'gray',
        };

        $label = $position->premarket_scan_type instanceof PremarketScanResult
            ? $position->premarket_scan_type->label()
            : 'Pre-Market';

        return [
            'price' => $price,
            'label' => $label,
            'description' => $card['description'] ?? null,
            'tone' => $tone,
            'checked' => true,
        ];
    }

    /**
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>|null
     */
    private function resolveBars(string $ticker, int $limit): ?array
    {
        $ticker = strtoupper(trim($ticker));
        $cacheKey = "vestix:price-chart-bars:{$ticker}:{$limit}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($ticker, $limit): ?array {
            $local = $this->localBars($ticker, $limit);

            // Only skip remote when local history covers the full requested window.
            // (Previously `min($limit, 30)` capped at 30, so 6M/1Y stuck on ~2–3 months of Sniper bars.)
            if (count($local) >= $limit) {
                return $local;
            }

            // Calendar lookback must exceed trading-day limit (~1.7×).
            $lookbackDays = (int) min(800, max((int) ceil($limit * 1.7), 60));

            $remote = $this->dailyBars->fetchRecentBars(
                $ticker,
                lookbackDays: $lookbackDays,
                limit: max($limit, 200),
            );

            if ($remote === null) {
                return count($local) >= 2 ? $local : null;
            }

            $remoteBars = array_values(array_map(
                static fn (array $bar): array => [
                    'open' => (float) $bar['open'],
                    'high' => (float) $bar['high'],
                    'low' => (float) $bar['low'],
                    'close' => (float) $bar['close'],
                    'volume' => (float) ($bar['volume'] ?? 0),
                    'date' => (string) $bar['date'],
                ],
                $remote['bars'],
            ));

            // Prefer the longer series when local and remote both exist.
            return count($remoteBars) >= count($local) ? $remoteBars : $local;
        });
    }

    /**
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>
     */
    private function localBars(string $ticker, int $limit): array
    {
        return SniperDailyBar::query()
            ->where('ticker', strtoupper(trim($ticker)))
            ->orderByDesc('date')
            ->limit($limit)
            ->get()
            ->sortBy('date')
            ->values()
            ->map(fn (SniperDailyBar $bar): array => [
                'open' => (float) $bar->open,
                'high' => (float) $bar->high,
                'low' => (float) $bar->low,
                'close' => (float) $bar->close,
                'volume' => (float) $bar->volume,
                'date' => $bar->date->toDateString(),
            ])
            ->all();
    }

    /**
     * @param  list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     */
    private function resolveEntryTime(array $bars, Position $position): ?string
    {
        if ($bars === []) {
            return null;
        }

        $entryPrice = $position->entry_price !== null ? (float) $position->entry_price : null;
        $signalDate = optional($position->signal_bar_date ?? $position->detected_signal_bar_date)
            ?->toDateString();
        $softAnchor = optional($position->entry_setup_captured_at)?->toDateString()
            ?? optional($position->created_at)?->toDateString();
        $short = $position->isShort();

        if ($entryPrice === null) {
            return $this->snapToBarDate($bars, $signalDate ?? $softAnchor);
        }

        $searchFrom = $signalDate ?? $softAnchor;

        if ($signalDate !== null) {
            $signalBar = null;
            foreach ($bars as $bar) {
                if ($bar['date'] === $signalDate) {
                    $signalBar = $bar;
                    break;
                }
            }

            // Buy-stop above signal high / sell-stop below signal low → fill next session.
            if ($signalBar !== null) {
                $blocksSameDay = $short
                    ? ((float) $signalBar['low'] > $entryPrice)
                    : ((float) $signalBar['high'] < $entryPrice);

                if ($blocksSameDay) {
                    $searchFrom = $this->nextBarDate($bars, $signalDate) ?? $signalDate;
                }
            }
        }

        foreach ($bars as $bar) {
            if ($searchFrom !== null && $bar['date'] < $searchFrom) {
                continue;
            }

            $hit = $short
                ? ((float) $bar['low'] <= $entryPrice)
                : ((float) $bar['high'] >= $entryPrice);

            if ($hit) {
                return $bar['date'];
            }
        }

        return $this->snapToBarDate($bars, $softAnchor ?? $signalDate);
    }

    /**
     * Only mark entry on the 1D chart when the fill is today (same NY session).
     *
     * @param  list<array{time: int, open?: float, high?: float, low?: float, close?: float}>  $bars
     */
    private function resolveIntradayEntryTime(array $bars, Position $position): ?int
    {
        if ($bars === [] || $position->entry_price === null) {
            return null;
        }

        $entryDate = optional($position->entry_setup_captured_at)?->timezone('America/New_York')?->toDateString()
            ?? optional($position->created_at)?->timezone('America/New_York')?->toDateString()
            ?? optional($position->signal_bar_date)?->toDateString();

        $todayNy = Carbon::now('America/New_York')->toDateString();

        if ($entryDate !== $todayNy) {
            return null;
        }

        $entryPrice = (float) $position->entry_price;
        $short = $position->isShort();

        foreach ($bars as $bar) {
            $high = (float) ($bar['high'] ?? $bar['close'] ?? 0);
            $low = (float) ($bar['low'] ?? $bar['close'] ?? 0);
            $hit = $short ? ($low <= $entryPrice) : ($high >= $entryPrice);

            if ($hit) {
                return (int) $bar['time'];
            }
        }

        return (int) $bars[0]['time'];
    }

    /**
     * @param  list<array{date: string}>  $bars
     */
    private function snapToBarDate(array $bars, ?string $date): ?string
    {
        if ($date === null || $bars === []) {
            return null;
        }

        $match = null;
        foreach ($bars as $bar) {
            if ($bar['date'] <= $date) {
                $match = $bar['date'];
            }
            if ($bar['date'] === $date) {
                return $date;
            }
        }

        return $match ?? $bars[0]['date'];
    }

    /**
     * @param  list<array{date: string}>  $bars
     */
    private function nextBarDate(array $bars, string $date): ?string
    {
        foreach ($bars as $bar) {
            if ($bar['date'] > $date) {
                return $bar['date'];
            }
        }

        return null;
    }

    /**
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>
     */
    private function demoBars(Position $position, int $count): array
    {
        $entry = (float) ($position->entry_price ?? $position->latest_close_price ?? 100);
        $anchor = optional($position->entry_setup_captured_at)?->toDateString()
            ?? optional($position->created_at)?->toDateString()
            ?? Carbon::today('America/New_York')->toDateString();

        $start = Carbon::parse($anchor, 'America/New_York')->subWeekdays(max(1, $count - 5));
        $price = $entry * 0.92;
        $bars = [];

        for ($i = 0; $i < $count; $i++) {
            $date = $start->copy()->addWeekdays($i)->toDateString();
            $noise = sin($i / 4) * ($entry * 0.006);
            $close = $price + (($entry - $price) * 0.12) + $noise;
            $open = $price;
            $high = max($open, $close) + abs($noise);
            $low = min($open, $close) - abs($noise);

            if ($i === $count - 5) {
                $high = max($high, $entry);
                $close = $entry;
            }

            $bars[] = [
                'open' => round($open, 4),
                'high' => round($high, 4),
                'low' => round($low, 4),
                'close' => round($close, 4),
                'volume' => 1_000_000 + ($i * 8_000),
                'date' => $date,
            ];
            $price = $close;
        }

        return $bars;
    }

    /**
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function demoIntradayBars(Position $position): array
    {
        $entry = (float) ($position->entry_price ?? $position->latest_close_price ?? 100);
        $start = Carbon::now('America/New_York')->startOfDay()->setTime(4, 0);
        $price = $entry * 0.99;
        $bars = [];

        for ($i = 0; $i < 78; $i++) {
            $time = $start->copy()->addMinutes($i * 5)->timestamp;
            $noise = sin($i / 6) * ($entry * 0.002);
            $close = $price + (($entry - $price) * 0.05) + $noise;
            $open = $price;

            $bars[] = [
                'time' => $time,
                'open' => round($open, 4),
                'high' => round(max($open, $close) + abs($noise), 4),
                'low' => round(min($open, $close) - abs($noise), 4),
                'close' => round($close, 4),
                'volume' => 50_000 + ($i * 100),
            ];
            $price = $close;
        }

        return $bars;
    }
}
