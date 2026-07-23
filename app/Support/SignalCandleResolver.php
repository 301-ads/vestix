<?php

namespace App\Support;

use App\Services\PolygonDailyBarService;

class SignalCandleResolver
{
    /**
     * @param  array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     * @return array{
     *     latest_bounce_bar: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
     *     latest_rejection_bar: array{date: string, open: float, high: float, low: float, close: float, volume: float}|null,
     * }
     */
    public static function resolveFromBars(array $bars): array
    {
        return [
            'latest_bounce_bar' => self::latestMatchingBar($bars, bounce: true),
            'latest_rejection_bar' => self::latestMatchingBar($bars, bounce: false),
        ];
    }

    /**
     * @param  array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     * @return array{date: string, open: float, high: float, low: float, close: float, volume: float}|null
     */
    public static function latestBounceBar(array $bars): ?array
    {
        return self::latestMatchingBar($bars, bounce: true);
    }

    /**
     * @param  array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     * @return array{date: string, open: float, high: float, low: float, close: float, volume: float}|null
     */
    public static function latestRejectionBar(array $bars): ?array
    {
        return self::latestMatchingBar($bars, bounce: false);
    }

    /**
     * @param  array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>  $bars
     * @return array{date: string, open: float, high: float, low: float, close: float, volume: float}|null
     */
    private static function latestMatchingBar(array $bars, bool $bounce): ?array
    {
        $count = count($bars);

        if ($count < 20) {
            return null;
        }

        $closes = array_column($bars, 'close');

        for ($index = $count - 1; $index >= 19; $index--) {
            $offsetFromEnd = $count - 1 - $index;
            $sma20 = TechnicalIndicators::smaAtOffset($closes, 20, $offsetFromEnd);

            if ($sma20 === null || $sma20 <= 0) {
                continue;
            }

            $bar = $bars[$index];
            $matches = $bounce
                ? PolygonDailyBarService::isBounceDay((float) $bar['low'], (float) $bar['close'], $sma20)
                : PolygonDailyBarService::isRejectionDay((float) $bar['high'], (float) $bar['close'], $sma20);

            if (! $matches) {
                continue;
            }

            return [
                'date' => (string) $bar['date'],
                'open' => (float) $bar['open'],
                'high' => (float) $bar['high'],
                'low' => (float) $bar['low'],
                'close' => (float) $bar['close'],
                'volume' => (float) $bar['volume'],
            ];
        }

        return null;
    }
}
