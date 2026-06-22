<?php

namespace App\Support;

use App\Enums\EarningsExitUrgency;
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

    public static function exitDeadline(Carbon $earningsDate): Carbon
    {
        return UsMarketSession::previousTradingDay($earningsDate->copy()->startOfDay());
    }

    public static function warningDate(Carbon $earningsDate): Carbon
    {
        return UsMarketSession::subtractTradingDays(self::exitDeadline($earningsDate), 1);
    }

    public static function actionDate(Carbon $earningsDate): Carbon
    {
        return self::exitDeadline($earningsDate);
    }

    public static function isWarningDay(Carbon $earningsDate, ?Carbon $today = null): bool
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();

        return $today->equalTo(self::warningDate($earningsDate));
    }

    public static function isActionDay(Carbon $earningsDate, ?Carbon $today = null): bool
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();

        return $today->equalTo(self::actionDate($earningsDate));
    }

    public static function urgency(Carbon $earningsDate, ?Carbon $today = null): ?EarningsExitUrgency
    {
        $today = ($today ?? Carbon::today('Europe/Amsterdam'))->copy()->startOfDay();
        $earnings = $earningsDate->copy()->startOfDay();

        if ($earnings->lessThan($today)) {
            return null;
        }

        $warning = self::warningDate($earningsDate);
        $action = self::actionDate($earningsDate);

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
}
