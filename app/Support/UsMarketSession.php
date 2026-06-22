<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class UsMarketSession
{
    public const MARKET_CLOSE_HOUR = 16;

    public const MARKET_CLOSE_MINUTE = 15;

    public const MARKET_OPEN_HOUR = 9;

    public const MARKET_OPEN_MINUTE = 30;

    public const PREMARKET_START_HOUR = 4;

    public const PREMARKET_START_MINUTE = 0;

    public const GATEKEEPER_START_HOUR = 14;

    public const GATEKEEPER_START_MINUTE = 55;

    public const GATEKEEPER_END_HOUR = 15;

    public const GATEKEEPER_END_MINUTE = 10;

    public static function expectedLastCompletedSessionDate(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now('America/New_York');
        $candidate = $now->copy()->startOfDay();

        if ($candidate->isWeekday() && $now->lt($candidate->copy()->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE))) {
            $candidate->subDay();
        }

        while (! $candidate->isWeekday()) {
            $candidate->subDay();
        }

        return $candidate;
    }

    public static function isBarStale(string $barDate, ?Carbon $now = null): bool
    {
        $bar = Carbon::parse($barDate, 'America/New_York')->startOfDay();
        $expected = self::expectedLastCompletedSessionDate($now);

        return $bar->lessThan($expected);
    }

    public static function isAfterMarketClose(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('America/New_York');

        if (! $now->isWeekday()) {
            return false;
        }

        return $now->greaterThanOrEqualTo(
            $now->copy()->startOfDay()->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE),
        );
    }

    public static function needsLatestSessionQuote(string $lastBarDate, ?Carbon $now = null): bool
    {
        return self::isBarStale($lastBarDate, $now);
    }

    public static function isUsTradingDay(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('America/New_York');

        return $now->isWeekday();
    }

    public static function currentUsTradingDay(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now('America/New_York');

        return $now->copy()->startOfDay();
    }

    public static function isPremarketWindow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('America/New_York');

        if (! self::isUsTradingDay($now)) {
            return false;
        }

        $start = $now->copy()->startOfDay()->setTime(self::PREMARKET_START_HOUR, self::PREMARKET_START_MINUTE);
        $open = $now->copy()->startOfDay()->setTime(self::MARKET_OPEN_HOUR, self::MARKET_OPEN_MINUTE);

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($open);
    }

    public static function isGatekeeperWindow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('Europe/Amsterdam');

        if (! $now->isWeekday()) {
            return false;
        }

        $start = $now->copy()->startOfDay()->setTime(self::GATEKEEPER_START_HOUR, self::GATEKEEPER_START_MINUTE);
        $end = $now->copy()->startOfDay()->setTime(self::GATEKEEPER_END_HOUR, self::GATEKEEPER_END_MINUTE);

        return $now->betweenIncluded($start, $end);
    }
}
