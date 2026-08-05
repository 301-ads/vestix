<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Models\SniperDailyBar;
use App\Support\TechnicalIndicators;
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
     * }|null
     */
    public function build(Position $position): ?array
    {
        $lookback = (int) config('vestix.academy.replay_lookback_bars', 120);
        $forward = (int) config('vestix.academy.replay_forward_bars', 20);

        $bars = $this->resolveBars($position->ticker, $lookback + $forward + 30);

        if ($bars === null || count($bars) < 30) {
            return null;
        }

        $anchorDate = optional($position->signal_bar_date ?? $position->detected_signal_bar_date)
            ?->toDateString();

        if ($anchorDate === null && $position->closed_at !== null) {
            $anchorDate = $position->closed_at->toDateString();
        }

        if ($anchorDate !== null) {
            $anchorIndex = null;

            foreach ($bars as $index => $bar) {
                if ($bar['date'] <= $anchorDate) {
                    $anchorIndex = $index;
                }
            }

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

        $entryTime = $anchorDate ?? ($candles[array_key_last($candles)]['time'] ?? null);
        $exitTime = optional($position->closed_at)?->toDateString();

        $markers = [];

        if ($entryTime !== null && $position->entry_price !== null) {
            $markers[] = [
                'time' => $entryTime,
                'position' => $position->isShort() ? 'aboveBar' : 'belowBar',
                'color' => '#22c55e',
                'shape' => $position->isShort() ? 'arrowDown' : 'arrowUp',
                'text' => 'Entry',
            ];
        }

        if ($exitTime !== null && $position->exit_price !== null) {
            $markers[] = [
                'time' => $exitTime,
                'position' => 'aboveBar',
                'color' => '#ef4444',
                'shape' => 'circle',
                'text' => 'Exit',
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
        ];
    }

    /**
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>|null
     */
    private function resolveBars(string $ticker, int $limit): ?array
    {
        $cacheKey = 'trade-replay:'.strtoupper($ticker).':'.$limit;

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($ticker, $limit): ?array {
            $local = SniperDailyBar::query()
                ->where('ticker', strtoupper(trim($ticker)))
                ->orderByDesc('date')
                ->limit($limit)
                ->get()
                ->sortBy('date')
                ->values();

            if ($local->count() >= 30) {
                return $local->map(fn (SniperDailyBar $bar): array => [
                    'open' => (float) $bar->open,
                    'high' => (float) $bar->high,
                    'low' => (float) $bar->low,
                    'close' => (float) $bar->close,
                    'volume' => (float) $bar->volume,
                    'date' => $bar->date->toDateString(),
                ])->all();
            }

            $remote = $this->dailyBars->fetchRecentBars($ticker, lookbackDays: max(90, $limit + 20), limit: $limit);

            if ($remote === null) {
                return null;
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
}
