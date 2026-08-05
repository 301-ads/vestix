<?php

namespace App\Support;

use App\Enums\EarningsExitUrgency;
use App\Enums\EarningsReleaseHour;
use Illuminate\Support\Carbon;

class EarningsExitSchedule
{
    public static function daysUntilEarnings(Carbon $earningsDate, ?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();
        $earnings = $earningsDate->copy()->startOfDay();

        if ($earnings->lessThanOrEqualTo($today)) {
            return 0;
        }

        return (int) $today->diffInDays($earnings);
    }

    public static function exitDeadline(Carbon $earningsDate, ?EarningsReleaseHour $hour = null): Carbon
    {
        $date = $earningsDate->copy()->startOfDay();

        if ($hour === EarningsReleaseHour::Amc) {
            return $date->isWeekday() ? $date : UsMarketSession::previousTradingDay($date);
        }

        return UsMarketSession::previousTradingDay($date);
    }

    public static function warningDate(Carbon $earningsDate, ?EarningsReleaseHour $hour = null): Carbon
    {
        return UsMarketSession::subtractTradingDays(self::exitDeadline($earningsDate, $hour), 1);
    }

    public static function actionDate(Carbon $earningsDate, ?EarningsReleaseHour $hour = null): Carbon
    {
        return self::exitDeadline($earningsDate, $hour);
    }

    public static function isWarningDay(Carbon $earningsDate, ?Carbon $today = null, ?EarningsReleaseHour $hour = null): bool
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();

        return $today->equalTo(self::warningDate($earningsDate, $hour));
    }

    public static function isActionDay(Carbon $earningsDate, ?Carbon $today = null, ?EarningsReleaseHour $hour = null): bool
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();

        return $today->equalTo(self::actionDate($earningsDate, $hour));
    }

    public static function daysUntilAction(Carbon $earningsDate, ?Carbon $today = null, ?EarningsReleaseHour $hour = null): int
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();
        $action = self::actionDate($earningsDate, $hour);

        if ($today->greaterThanOrEqualTo($action)) {
            return 0;
        }

        return (int) $today->diffInDays($action);
    }

    public static function isWeekendReminderDay(Carbon $earningsDate, ?Carbon $today = null, ?EarningsReleaseHour $hour = null): bool
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();

        if (! $today->isWeekend()) {
            return false;
        }

        return self::actionDate($earningsDate, $hour)->equalTo(UsMarketSession::nextTradingDay($today));
    }

    public static function urgency(Carbon $earningsDate, ?Carbon $today = null, ?EarningsReleaseHour $hour = null): ?EarningsExitUrgency
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();
        $earnings = $earningsDate->copy()->startOfDay();

        if ($earnings->lessThan($today)) {
            return null;
        }

        $warning = self::warningDate($earningsDate, $hour);
        $action = self::actionDate($earningsDate, $hour);

        if ($today->greaterThan($action)) {
            return EarningsExitUrgency::Overdue;
        }

        if ($today->equalTo($action)) {
            return EarningsExitUrgency::ExitToday;
        }

        if ($today->greaterThanOrEqualTo($warning)) {
            return EarningsExitUrgency::Prepare;
        }

        return null;
    }

    public static function quarantineTradingDays(): int
    {
        return max(0, (int) config('vestix.earnings_quarantine.trading_days', 2));
    }

    public static function isDateInEntryQuarantine(Carbon $earningsDate, ?Carbon $today = null, ?int $tradingDays = null): bool
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();
        $event = $earningsDate->copy()->startOfDay();
        $tradingDays ??= self::quarantineTradingDays();

        if ($tradingDays === 0) {
            return $today->equalTo($event);
        }

        $windowStart = UsMarketSession::subtractTradingDays($event, $tradingDays);
        $windowEnd = UsMarketSession::addTradingDays($event, $tradingDays);

        return $today->betweenIncluded($windowStart, $windowEnd);
    }

    public static function isInEntryQuarantine(?Carbon $lastEarningsDate, ?Carbon $nextEarningsDate, ?Carbon $today = null, ?int $tradingDays = null): bool
    {
        if ($lastEarningsDate !== null && self::isDateInEntryQuarantine($lastEarningsDate, $today, $tradingDays)) {
            return true;
        }

        if ($nextEarningsDate !== null && self::isDateInEntryQuarantine($nextEarningsDate, $today, $tradingDays)) {
            return true;
        }

        return false;
    }

    public static function entryQuarantineHardFailMessage(?int $tradingDays = null): string
    {
        $tradingDays ??= self::quarantineTradingDays();

        return "Earnings-quarantaine: binnen {$tradingDays} handelsdagen van cijfers (trampolinesignaal onbetrouwbaar)";
    }
}
