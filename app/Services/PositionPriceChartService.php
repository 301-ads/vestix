<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Models\SniperDailyBar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PositionPriceChartService
{
    public const RANGES = ['1W', '1M', '3M', '6M', '1Y'];

    /** Approximate trading-day windows per range key. */
    private const RANGE_BARS = [
        '1W' => 5,
        '1M' => 22,
        '3M' => 66,
        '6M' => 132,
        '1Y' => 252,
    ];

    public function __construct(
        private DailyBarProvider $dailyBars,
    ) {}

    /**
     * @return array{
     *     ticker: string,
     *     range: string,
     *     points: list<array{time: string, value: float}>,
     *     period_change: array{absolute: float, percent: float, positive: bool},
     *     levels: array{entry: ?float, stop: ?float, target1: ?float},
     *     markers: list<array{time: string, role: string, color: string, value: float}>,
     *     entry_time: ?string,
     *     short: bool,
     *     demo: bool,
     * }|null
     */
    public function build(Position $position, string $range = '3M'): ?array
    {
        $range = $this->normalizeRange($range);
        $barCount = self::RANGE_BARS[$range];
        $fetchLimit = max($barCount + 40, 100);

        $bars = $this->resolveBars($position->ticker, $fetchLimit);
        $demo = false;

        if ($bars === null || count($bars) < 2) {
            $bars = $this->demoBars($position, $barCount + 20);
            $demo = true;
        }

        $window = array_slice($bars, -$barCount);
        if (count($window) < 2) {
            return null;
        }

        $points = [];
        foreach ($window as $bar) {
            $points[] = [
                'time' => $bar['date'],
                'value' => round((float) $bar['close'], 4),
            ];
        }

        $first = (float) $points[0]['value'];
        $last = (float) $points[array_key_last($points)]['value'];
        $absolute = round($last - $first, 4);
        $percent = $first != 0.0 ? round(($absolute / $first) * 100, 2) : 0.0;

        $short = $position->isShort();
        $entryPrice = $position->entry_price !== null ? (float) $position->entry_price : null;
        $entryTime = $this->resolveEntryTime($bars, $position);

        $windowStart = $window[0]['date'];
        $windowEnd = $window[array_key_last($window)]['date'];
        $markers = [];

        if (
            $entryTime !== null
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

        return [
            'ticker' => $position->ticker,
            'range' => $range,
            'points' => $points,
            'period_change' => [
                'absolute' => $absolute,
                'percent' => $percent,
                'positive' => $absolute >= 0,
            ],
            'levels' => [
                'entry' => $entryPrice,
                'stop' => $position->current_sl !== null
                    ? (float) $position->current_sl
                    : ($position->initial_sl !== null ? (float) $position->initial_sl : null),
                'target1' => $position->plannedBracketTarget1Price(),
            ],
            'markers' => $markers,
            'entry_time' => $entryTime,
            'short' => $short,
            'demo' => $demo,
        ];
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
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>|null
     */
    private function resolveBars(string $ticker, int $limit): ?array
    {
        $ticker = strtoupper(trim($ticker));
        $cacheKey = "vestix:price-chart-bars:{$ticker}:{$limit}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($ticker, $limit): ?array {
            $local = $this->localBars($ticker, $limit);

            if (count($local) >= min($limit, 30)) {
                return $local;
            }

            $remote = $this->dailyBars->fetchRecentBars(
                $ticker,
                lookbackDays: (int) min(500, max($limit + 30, 60)),
                limit: max($limit, 200),
            );

            if ($remote === null) {
                return count($local) >= 2 ? $local : null;
            }

            return array_values(array_map(
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
}
