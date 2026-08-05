<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Models\SniperDailyBar;
use App\Support\TechnicalIndicators;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TradeReplayService
{
    public function __construct(
        private DailyBarProvider $dailyBars,
    ) {}

    /**
     * @return array{
     *     ticker: string,
     *     candles: list<array{time: string, open: float, high: float, low: float, close: float}>,
     *     sma20: list<array{time: string, value: float}>,
     *     rsi14: list<array{time: string, value: float}>,
     *     markers: list<array{time: string, position: string, color: string, shape: string, text: string}>,
     *     levels: array{entry: ?float, stop: ?float, target1: ?float, exit: ?float},
     *     demo: bool,
     * }|null
     */
    public function build(Position $position, bool $allowDemoFallback = true): ?array
    {
        $lookback = (int) config('vestix.academy.replay_lookback_bars', 120);
        $forward = (int) config('vestix.academy.replay_forward_bars', 20);

        $anchorDate = optional($position->signal_bar_date ?? $position->detected_signal_bar_date)
            ?->toDateString();

        if ($anchorDate === null && $position->closed_at !== null) {
            $anchorDate = $position->closed_at->toDateString();
        }

        $needed = $lookback + $forward + 40;
        $bars = $this->resolveBars($position->ticker, $needed, $anchorDate);
        $demo = false;

        if ($bars === null || count($bars) < 30) {
            if (! $allowDemoFallback) {
                return null;
            }

            $bars = $this->demoBars($position, $lookback, $forward);
            $demo = true;
            $anchorDate = $bars[min(count($bars) - 1 - max(0, $forward), max(0, count($bars) - 1))]['date'] ?? $anchorDate;
        }

        if ($anchorDate !== null) {
            $anchorIndex = $this->indexAtOrBefore($bars, $anchorDate);

            if ($anchorIndex !== null) {
                $start = max(0, $anchorIndex - $lookback + 1);
                $end = min(count($bars) - 1, $anchorIndex + $forward);
                $bars = array_slice($bars, $start, $end - $start + 1);
            }
        }

        $closes = array_column($bars, 'close');
        $candles = [];
        $sma20 = [];
        $rsi14 = [];

        foreach ($bars as $index => $bar) {
            $time = $bar['date'];
            $candles[] = [
                'time' => $time,
                'open' => round((float) $bar['open'], 4),
                'high' => round((float) $bar['high'], 4),
                'low' => round((float) $bar['low'], 4),
                'close' => round((float) $bar['close'], 4),
            ];

            $slice = array_slice($closes, 0, $index + 1);
            $sma = TechnicalIndicators::sma($slice, 20);

            if ($sma !== null) {
                $sma20[] = ['time' => $time, 'value' => round($sma, 4)];
            }

            $rsi = TechnicalIndicators::wilderRsi($slice, 14);

            if ($rsi !== null) {
                $rsi14[] = ['time' => $time, 'value' => round($rsi, 2)];
            }
        }

        $entryPrice = $position->entry_price !== null ? (float) $position->entry_price : null;
        $exitPrice = $position->exit_price !== null ? (float) $position->exit_price : null;

        // Prefer the bar whose range actually contains the fill — not merely the signal date
        // (signal candle can sit well above/below the eventual entry price).
        $entryTime = $this->resolveMarkerTime(
            $candles,
            $entryPrice,
            $anchorDate,
            preferFromDate: $anchorDate,
        );
        $exitTime = $this->resolveMarkerTime(
            $candles,
            $exitPrice,
            optional($position->closed_at)?->toDateString(),
            preferFromDate: $entryTime,
        );

        $markers = [];

        if ($entryTime !== null && $entryPrice !== null) {
            $markers[] = [
                'time' => $entryTime,
                // Price-based marker so the arrow sits on the Entry line, not on the candle wick.
                'position' => $position->isShort() ? 'atPriceTop' : 'atPriceBottom',
                'color' => '#22c55e',
                'shape' => $position->isShort() ? 'arrowDown' : 'arrowUp',
                'text' => 'Entry',
                'price' => $entryPrice,
            ];
        }

        if ($exitTime !== null && $exitPrice !== null) {
            $markers[] = [
                'time' => $exitTime,
                'position' => 'atPriceMiddle',
                'color' => '#ef4444',
                'shape' => 'circle',
                'text' => 'Exit',
                'price' => $exitPrice,
            ];
        }

        return [
            'ticker' => $position->ticker,
            'candles' => $candles,
            'sma20' => $sma20,
            'rsi14' => $rsi14,
            'markers' => $markers,
            'levels' => [
                'entry' => $position->entry_price !== null ? (float) $position->entry_price : null,
                'stop' => $position->initial_sl !== null
                    ? (float) $position->initial_sl
                    : ($position->current_sl !== null ? (float) $position->current_sl : null),
                'target1' => $position->plannedBracketTarget1Price(),
                'exit' => $position->exit_price !== null ? (float) $position->exit_price : null,
            ],
            'demo' => $demo,
        ];
    }

    /**
     * @param  list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     */
    private function indexAtOrBefore(array $bars, string $date): ?int
    {
        $anchorIndex = null;

        foreach ($bars as $index => $bar) {
            if ($bar['date'] <= $date) {
                $anchorIndex = $index;
            }
        }

        return $anchorIndex;
    }

    /**
     * @param  list<array{time: string, open: float, high: float, low: float, close: float}>  $candles
     */
    private function resolveMarkerTime(
        array $candles,
        ?float $price,
        ?string $preferredDate,
        ?string $preferFromDate = null,
    ): ?string {
        if ($candles === []) {
            return null;
        }

        if ($price !== null) {
            $match = $this->firstBarContainingPrice($candles, $price, $preferFromDate ?? $preferredDate);

            if ($match !== null) {
                return $match;
            }
        }

        $times = array_column($candles, 'time');

        return $this->snapToCandleTime($times, $preferredDate);
    }

    /**
     * @param  list<array{time: string, high: float, low: float}>  $candles
     */
    private function firstBarContainingPrice(array $candles, float $price, ?string $notBefore): ?string
    {
        foreach ($candles as $candle) {
            if ($notBefore !== null && $candle['time'] < $notBefore) {
                continue;
            }

            if ($price >= (float) $candle['low'] && $price <= (float) $candle['high']) {
                return $candle['time'];
            }
        }

        // Fallback: closest close to the fill price (still after notBefore when set).
        $bestTime = null;
        $bestDistance = null;

        foreach ($candles as $candle) {
            if ($notBefore !== null && $candle['time'] < $notBefore) {
                continue;
            }

            $distance = abs((float) $candle['close'] - $price);

            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestTime = $candle['time'];
            }
        }

        return $bestTime;
    }

    /**
     * @param  list<string>  $candleTimes
     */
    private function snapToCandleTime(array $candleTimes, ?string $date): ?string
    {
        if ($date === null || $candleTimes === []) {
            return null;
        }

        if (in_array($date, $candleTimes, true)) {
            return $date;
        }

        $best = null;

        foreach ($candleTimes as $time) {
            if ($time <= $date) {
                $best = $time;
            }
        }

        return $best ?? $candleTimes[0];
    }

    /**
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>|null
     */
    private function resolveBars(string $ticker, int $limit, ?string $anchorDate): ?array
    {
        $cacheKey = 'trade-replay:v2:'.strtoupper($ticker).':'.$limit.':'.($anchorDate ?? 'latest');

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($ticker, $limit, $anchorDate): ?array {
            $local = $this->localBars($ticker, max($limit, 200));

            if ($this->coversAnchor($local, $anchorDate, minBars: 40)) {
                return $local;
            }

            // Archive trades often need history beyond the sniper cache window.
            $lookbackDays = max(180, $limit + 60);
            if ($anchorDate !== null) {
                $daysSinceAnchor = Carbon::parse($anchorDate, 'America/New_York')
                    ->diffInDays(Carbon::today('America/New_York'));
                $lookbackDays = max($lookbackDays, $daysSinceAnchor + $limit + 10);
            }

            $remote = $this->dailyBars->fetchRecentBars(
                $ticker,
                lookbackDays: (int) min(500, $lookbackDays),
                limit: max($limit, 200),
            );

            if ($remote === null) {
                return $this->coversAnchor($local, $anchorDate, minBars: 30) ? $local : null;
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
     * @param  list<array{date: string}>  $bars
     */
    private function coversAnchor(array $bars, ?string $anchorDate, int $minBars): bool
    {
        if (count($bars) < $minBars) {
            return false;
        }

        if ($anchorDate === null) {
            return true;
        }

        return $this->indexAtOrBefore($bars, $anchorDate) !== null;
    }

    /**
     * Synthetic OHLC so the UI can be reviewed when market APIs are empty.
     *
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>
     */
    private function demoBars(Position $position, int $lookback, int $forward): array
    {
        $entry = (float) ($position->entry_price ?? $position->latest_close_price ?? 100);
        $exit = (float) ($position->exit_price ?? $entry);
        $stop = (float) ($position->initial_sl ?? $position->current_sl ?? ($entry * 0.97));
        $short = $position->isShort();
        $anchor = optional($position->signal_bar_date ?? $position->closed_at)?->toDateString()
            ?? Carbon::today('America/New_York')->toDateString();

        $total = $lookback + $forward;
        $start = Carbon::parse($anchor, 'America/New_York')->subWeekdays($lookback - 1);
        $price = $entry * ($short ? 1.08 : 0.92);
        $bars = [];

        for ($i = 0; $i < $total; $i++) {
            $date = $start->copy()->addWeekdays($i)->toDateString();
            $noise = sin($i / 3) * ($entry * 0.004);

            if ($i < $lookback - 1) {
                $target = $entry;
            } elseif ($i === $lookback - 1) {
                $target = $entry;
            } else {
                $target = $exit;
            }

            $close = $price + (($target - $price) * 0.18) + $noise;
            $open = $price;
            $high = max($open, $close) + abs($noise);
            $low = min($open, $close) - abs($noise);

            if ($i === $lookback - 1) {
                $low = min($low, $short ? $entry : min($entry, $stop));
                $high = max($high, $short ? max($entry, $stop) : $entry);
                $close = $entry;
            }

            $bars[] = [
                'open' => round($open, 4),
                'high' => round($high, 4),
                'low' => round($low, 4),
                'close' => round($close, 4),
                'volume' => 1_000_000 + ($i * 10_000),
                'date' => $date,
            ];
            $price = $close;
        }

        return $bars;
    }
}
