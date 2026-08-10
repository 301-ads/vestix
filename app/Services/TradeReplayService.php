<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Models\SniperDailyBar;
use App\Support\TechnicalIndicators;
use App\Support\UsMarketSession;
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
     *     markers: list<array{time: string, role: string, position: string, color: string, direction: string}>,
     *     levels: array{entry: ?float, stop: ?float, target1: ?float, exit: ?float},
     *     fog_time: ?string,
     *     entry_time: ?string,
     *     exit_time: ?string,
     *     short: bool,
     *     demo: bool,
     * }|null
     */
    public function build(Position $position, bool $allowDemoFallback = true): ?array
    {
        $lookback = (int) config('vestix.academy.replay_lookback_bars', 120);
        $forward = (int) config('vestix.academy.replay_forward_bars', 20);
        // Warmup so SMA(20) / RSI(14) exist from the first visible candle.
        $warmup = 30;

        $signalDate = optional($position->signal_bar_date ?? $position->detected_signal_bar_date)
            ?->toDateString();
        // Never fall back to closed_at — that puts Fog of War on the exit day (HALO bug).
        $anchorDate = $signalDate
            ?? optional($position->entry_setup_captured_at)?->toDateString()
            ?? optional($position->created_at)?->toDateString();

        $needed = $lookback + $forward + $warmup + 40;
        $closedDate = optional($position->closed_at)?->toDateString();
        $bars = $this->resolveBars(
            $position->ticker,
            $needed,
            $anchorDate,
            minBeforeAnchor: $lookback + $warmup,
            throughDate: $closedDate,
        );
        $demo = false;

        if ($bars === null || count($bars) < 30) {
            if (! $allowDemoFallback) {
                return null;
            }

            $bars = $this->demoBars($position, $lookback + $warmup, $forward);
            $demo = true;
            $anchorDate = $bars[min(count($bars) - 1 - max(0, $forward), max(0, count($bars) - 1))]['date'] ?? $anchorDate;
        }

        $displayStart = 0;
        $displayEnd = count($bars) - 1;

        if ($anchorDate !== null) {
            $anchorIndex = $this->indexAtOrBefore($bars, $anchorDate);

            if ($anchorIndex !== null) {
                $displayStart = max(0, $anchorIndex - $lookback + 1);
                $displayEnd = min(count($bars) - 1, $anchorIndex + $forward);

                // Long holds need candles through exit for reveal, not only +forward from entry.
                if ($closedDate !== null) {
                    $exitIndex = $this->indexAtOrBefore($bars, $closedDate);
                    if ($exitIndex !== null) {
                        $displayEnd = max($displayEnd, $exitIndex);
                    }
                }
            }
        }

        // Compute indicators on bars before the visible window so SMA/RSI start on day 1.
        $calcStart = max(0, $displayStart - $warmup);
        $closes = [];
        for ($i = $calcStart; $i <= $displayEnd; $i++) {
            $closes[] = (float) $bars[$i]['close'];
        }

        $candles = [];
        $sma20 = [];
        $rsi14 = [];

        for ($i = $displayStart; $i <= $displayEnd; $i++) {
            $bar = $bars[$i];
            $time = $bar['date'];
            $candles[] = [
                'time' => $time,
                'open' => round((float) $bar['open'], 4),
                'high' => round((float) $bar['high'], 4),
                'low' => round((float) $bar['low'], 4),
                'close' => round((float) $bar['close'], 4),
            ];

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

        $entryPrice = $position->entry_price !== null ? (float) $position->entry_price : null;
        $exitPrice = $position->exit_price !== null ? (float) $position->exit_price : null;
        $short = $position->isShort();

        $entryTime = $this->resolveEntryFillTime(
            $candles,
            $entryPrice,
            $signalDate,
            $short,
            softAnchorDate: $anchorDate,
            searchUntil: optional($position->closed_at)?->toDateString(),
        );
        $exitTime = $this->resolveExitTime(
            $candles,
            $exitPrice,
            $closedDate,
            $entryTime,
            $short,
        );
        // Fog ends on the bounce/decision bar — never include the fill candle.
        $fogTime = $this->resolveFogTime($candles, $signalDate, $entryTime);

        $markers = [];

        if ($entryTime !== null && $entryPrice !== null) {
            // Long entry = green ▲ below bar; short entry = red ▼ above bar.
            $markers[] = [
                'time' => $entryTime,
                'role' => 'entry',
                'position' => $short ? 'aboveBar' : 'belowBar',
                'color' => $short ? '#ef4444' : '#22c55e',
                'direction' => $short ? 'down' : 'up',
            ];
        }

        if ($exitTime !== null && $exitPrice !== null) {
            // Long exit = red ▼ above bar; short cover = green ▲ below bar.
            $markers[] = [
                'time' => $exitTime,
                'role' => 'exit',
                'position' => $short ? 'belowBar' : 'aboveBar',
                'color' => $short ? '#22c55e' : '#ef4444',
                'direction' => $short ? 'up' : 'down',
            ];
        }

        return [
            'ticker' => $position->ticker,
            'candles' => $candles,
            'sma20' => $sma20,
            'rsi14' => $rsi14,
            'levels' => [
                'entry' => $position->entry_price !== null ? (float) $position->entry_price : null,
                'stop' => $position->initial_sl !== null
                    ? (float) $position->initial_sl
                    : ($position->current_sl !== null ? (float) $position->current_sl : null),
                'target1' => $position->plannedBracketTarget1Price(),
                'exit' => $position->exit_price !== null ? (float) $position->exit_price : null,
            ],
            'markers' => $markers,
            'fog_time' => $fogTime,
            'entry_time' => $entryTime,
            'exit_time' => $exitTime,
            'short' => $short,
            'demo' => $demo,
        ];
    }

    /**
     * Last visible candle before reveal: the bounce/signal bar when it precedes the fill,
     * otherwise the session immediately before entry. Never the fill candle itself.
     *
     * @param  list<array{time: string, open: float, high: float, low: float, close: float}>  $candles
     */
    private function resolveFogTime(array $candles, ?string $signalDate, ?string $entryTime): ?string
    {
        if ($candles === [] || $entryTime === null) {
            return null;
        }

        if ($signalDate !== null && $signalDate < $entryTime) {
            $snapped = $this->snapToCandleTime(array_column($candles, 'time'), $signalDate);

            if ($snapped !== null && $snapped < $entryTime) {
                return $snapped;
            }
        }

        return $this->previousCandleTime($candles, $entryTime);
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
     * Buy-stop / sell-stop fill day: first session that actually reaches the trigger.
     * Do NOT use "price inside candle range" — a long wick on the signal day falsely matches.
     *
     * Without a signal date, infer the most recent breakout of entry before exit
     * (near softAnchor / created_at) instead of treating the exit day as entry.
     *
     * @param  list<array{time: string, open: float, high: float, low: float, close: float}>  $candles
     */
    private function resolveEntryFillTime(
        array $candles,
        ?float $entryPrice,
        ?string $signalDate,
        bool $short,
        ?string $softAnchorDate = null,
        ?string $searchUntil = null,
    ): ?string {
        if ($candles === [] || $entryPrice === null) {
            return $this->snapToCandleTime(
                array_column($candles, 'time'),
                $signalDate ?? $softAnchorDate,
            );
        }

        if ($signalDate === null) {
            return $this->inferEntryFillWithoutSignal(
                $candles,
                $entryPrice,
                $short,
                $softAnchorDate,
                $searchUntil,
            );
        }

        $searchFrom = $signalDate;
        $signalCandle = null;

        foreach ($candles as $candle) {
            if ($candle['time'] === $signalDate) {
                $signalCandle = $candle;
                break;
            }
        }

        // Vestix default: long buy-stop above signal high / short sell-stop below signal low.
        // Fill cannot be on the signal bar in that case — start the day after.
        if ($signalCandle !== null) {
            $signalBlocksSameDay = $short
                ? ((float) $signalCandle['low'] > $entryPrice)
                : ((float) $signalCandle['high'] < $entryPrice);

            if ($signalBlocksSameDay) {
                $searchFrom = $this->nextCandleTime($candles, $signalDate);
            }
        }

        foreach ($candles as $candle) {
            if ($searchFrom !== null && $candle['time'] < $searchFrom) {
                continue;
            }

            if ($searchUntil !== null && $candle['time'] > $searchUntil) {
                break;
            }

            if ($short) {
                if ((float) $candle['low'] <= $entryPrice) {
                    return $candle['time'];
                }
            } elseif ((float) $candle['high'] >= $entryPrice) {
                return $candle['time'];
            }
        }

        return $this->snapToCandleTime(array_column($candles, 'time'), $signalDate);
    }

    /**
     * When signal_bar_date is missing, find the last breakout of entry_price before exit
     * (or nearest to created_at). Avoids Fog ending on closed_at when price is already extended.
     *
     * @param  list<array{time: string, open: float, high: float, low: float, close: float}>  $candles
     */
    private function inferEntryFillWithoutSignal(
        array $candles,
        float $entryPrice,
        bool $short,
        ?string $softAnchorDate,
        ?string $searchUntil,
    ): ?string {
        $breakouts = [];

        for ($i = 1, $count = count($candles); $i < $count; $i++) {
            $prev = $candles[$i - 1];
            $cur = $candles[$i];

            if ($searchUntil !== null && $cur['time'] > $searchUntil) {
                break;
            }

            $isBreakout = $short
                ? ((float) $prev['low'] > $entryPrice && (float) $cur['low'] <= $entryPrice)
                : ((float) $prev['high'] < $entryPrice && (float) $cur['high'] >= $entryPrice);

            if ($isBreakout) {
                $breakouts[] = $cur['time'];
            }
        }

        if ($breakouts === []) {
            return $this->snapToCandleTime(array_column($candles, 'time'), $softAnchorDate)
                ?? ($candles[0]['time'] ?? null);
        }

        if ($softAnchorDate === null) {
            return $breakouts[array_key_last($breakouts)];
        }

        $best = $breakouts[0];
        $bestDistance = abs(Carbon::parse($best)->diffInDays(Carbon::parse($softAnchorDate)));

        foreach ($breakouts as $time) {
            $distance = abs(Carbon::parse($time)->diffInDays(Carbon::parse($softAnchorDate)));
            if ($distance < $bestDistance) {
                $best = $time;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  list<array{time: string, open: float, high: float, low: float, close: float}>  $candles
     */
    private function resolveExitTime(
        array $candles,
        ?float $exitPrice,
        ?string $closedDate,
        ?string $entryTime,
        bool $short,
    ): ?string {
        if ($candles === []) {
            return null;
        }

        $times = array_column($candles, 'time');

        // Only place the exit on closed_at when that session bar is actually present.
        // Snapping to an older bar (e.g. Friday while closed Monday) mislabels the kick-out.
        if ($closedDate !== null && in_array($closedDate, $times, true)) {
            return $closedDate;
        }

        if ($exitPrice === null || $closedDate !== null) {
            // closed_at set but bar not loaded yet — wait; don't fake exit on an earlier day.
            return null;
        }

        $searchFrom = $entryTime;

        foreach ($candles as $candle) {
            if ($searchFrom !== null && $candle['time'] < $searchFrom) {
                continue;
            }

            if ($exitPrice >= (float) $candle['low'] && $exitPrice <= (float) $candle['high']) {
                return $candle['time'];
            }
        }

        return $entryTime;
    }

    /**
     * @param  list<array{time: string}>  $candles
     */
    private function nextCandleTime(array $candles, string $date): ?string
    {
        $found = false;

        foreach ($candles as $candle) {
            if ($found) {
                return $candle['time'];
            }

            if ($candle['time'] === $date) {
                $found = true;
            }
        }

        return null;
    }

    /**
     * @param  list<array{time: string}>  $candles
     */
    private function previousCandleTime(array $candles, string $date): ?string
    {
        $previous = null;

        foreach ($candles as $candle) {
            if ($candle['time'] === $date) {
                return $previous;
            }

            $previous = $candle['time'];
        }

        return null;
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
    private function resolveBars(
        string $ticker,
        int $limit,
        ?string $anchorDate,
        int $minBeforeAnchor = 0,
        ?string $throughDate = null,
    ): ?array {
        $requiredThrough = $this->requiredThroughDate($throughDate);
        $cacheKey = 'trade-replay:v9:'.strtoupper($ticker).':'.$limit.':'.($anchorDate ?? 'latest').':'.$minBeforeAnchor.':'.($requiredThrough ?? 'open');

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($ticker, $limit, $anchorDate, $minBeforeAnchor, $requiredThrough): ?array {
            $local = $this->localBars($ticker, max($limit, 200));

            if (
                $this->coversAnchor($local, $anchorDate, minBars: max(40, $minBeforeAnchor), minBeforeAnchor: $minBeforeAnchor)
                && $this->coversThrough($local, $requiredThrough)
            ) {
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
                return $this->coversAnchor($local, $anchorDate, minBars: 30, minBeforeAnchor: 0) ? $local : null;
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
     * Don't require a session bar that is not completed yet (before US close).
     */
    private function requiredThroughDate(?string $throughDate): ?string
    {
        if ($throughDate === null) {
            return null;
        }

        $expectedCompleted = UsMarketSession::expectedLastCompletedSessionDate()->toDateString();

        return $throughDate > $expectedCompleted ? $expectedCompleted : $throughDate;
    }

    /**
     * @param  list<array{date: string}>  $bars
     */
    private function coversThrough(array $bars, ?string $throughDate): bool
    {
        if ($throughDate === null || $bars === []) {
            return true;
        }

        return $bars[array_key_last($bars)]['date'] >= $throughDate;
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
    private function coversAnchor(array $bars, ?string $anchorDate, int $minBars, int $minBeforeAnchor = 0): bool
    {
        if (count($bars) < $minBars) {
            return false;
        }

        if ($anchorDate === null) {
            return true;
        }

        $anchorIndex = $this->indexAtOrBefore($bars, $anchorDate);

        if ($anchorIndex === null) {
            return false;
        }

        // Require enough sessions at/before the anchor so lookback + indicator warmup fit.
        return ($anchorIndex + 1) >= $minBeforeAnchor;
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
        $anchor = optional($position->signal_bar_date ?? $position->detected_signal_bar_date)?->toDateString()
            ?? optional($position->entry_setup_captured_at)?->toDateString()
            ?? optional($position->created_at)?->toDateString()
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
