<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class UsMarketSession
{
    public const MARKET_CLOSE_HOUR = 16;

    public const MARKET_CLOSE_MINUTE = 15;

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
        return self::isBarStale($lastBarDate, $now) || self::isAfterMarketClose($now);
    }
}
