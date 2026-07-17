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

    /**
     * Daily trailing SL (SMA/ATR) is an end-of-day protocol.
     * Surface raise-todos after US close, overnight, weekends, and before the next open — not during RTH.
     */
    public static function isTrailingStopReviewWindow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('America/New_York');

        if (! $now->isWeekday()) {
            return true;
        }

        $open = $now->copy()->startOfDay()->setTime(self::MARKET_OPEN_HOUR, self::MARKET_OPEN_MINUTE);
        $close = $now->copy()->startOfDay()->setTime(self::MARKET_CLOSE_HOUR, self::MARKET_CLOSE_MINUTE);

        return $now->lt($open) || $now->greaterThanOrEqualTo($close);
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

        $start = self::gatekeeperWindowStart($now);
        $end = self::gatekeeperWindowEnd($now);

        return $now->betweenIncluded($start, $end);
    }

    public static function gatekeeperWindowStart(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now('Europe/Amsterdam');

        return self::parseGatekeeperTime(
            (string) config('vestix.premarket.gatekeeper_window_start', '14:25'),
            $now,
        );
    }

    public static function gatekeeperWindowEnd(?Carbon $now = null): Carbon
    {
        $now ??= Carbon::now('Europe/Amsterdam');

        return self::parseGatekeeperTime(
            (string) config('vestix.premarket.gatekeeper_window_end', '15:15'),
            $now,
        );
    }

    public static function gatekeeperWindowLabel(): string
    {
        $start = (string) config('vestix.premarket.gatekeeper_window_start', '14:25');
        $end = (string) config('vestix.premarket.gatekeeper_window_end', '15:15');

        return "{$start}–{$end} Amsterdam";
    }

    private static function parseGatekeeperTime(string $time, Carbon $day): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $day->copy()->startOfDay()->setTime((int) $hour, (int) $minute);
    }

    public static function previousTradingDay(Carbon $date): Carbon
    {
        $candidate = $date->copy()->startOfDay()->subDay();

        while (! $candidate->isWeekday()) {
            $candidate->subDay();
        }

        return $candidate->startOfDay();
    }

    public static function nextTradingDay(Carbon $date): Carbon
    {
        $candidate = $date->copy()->startOfDay()->addDay();

        while (! $candidate->isWeekday()) {
            $candidate->addDay();
        }

        return $candidate->startOfDay();
    }

    public static function subtractTradingDays(Carbon $date, int $days): Carbon
    {
        $candidate = $date->copy()->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            $candidate = self::previousTradingDay($candidate);
        }

        return $candidate;
    }

    public static function isIntradayTargetWatchWindow(?Carbon $now = null): bool
    {
        $now ??= Carbon::now('America/New_York');

        if (! self::isUsTradingDay($now)) {
            return false;
        }

        [$startHour, $startMinute] = self::parseClockTime(
            (string) config('vestix.intraday_target_watch.window_start', '04:00'),
        );
        [$endHour, $endMinute] = self::parseClockTime(
            (string) config('vestix.intraday_target_watch.window_end', '16:15'),
        );

        $start = $now->copy()->startOfDay()->setTime($startHour, $startMinute);
        $end = $now->copy()->startOfDay()->setTime($endHour, $endMinute);

        return $now->betweenIncluded($start, $end);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseClockTime(string $time): array
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return [(int) $hour, (int) $minute];
    }
}
