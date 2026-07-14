<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Enums\EarningsReleaseHour;
use App\Models\Position;
use App\Support\EarningsExitSchedule;
use Illuminate\Support\Carbon;

class EarningsExitAlertService
{
    public function __construct(
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * @return array{warning: int, action: int, weekend: int, final: int}
     */
    public function run(string $phase, ?Carbon $today = null): array
    {
        $today ??= Carbon::today('Europe/Amsterdam');
        $summary = ['warning' => 0, 'action' => 0, 'weekend' => 0, 'final' => 0];

        $positions = Position::query()
            ->open()
            ->with('asset')
            ->get()
            ->filter(fn (Position $position): bool => $position->effectiveEarningsDate() !== null
                && ! $position->heldThroughEarningsForCurrentCycle());

        foreach ($positions as $position) {
            $earningsDate = $position->effectiveEarningsDate();

            if ($earningsDate === null) {
                continue;
            }

            $hour = $position->asset?->effectiveEarningsHour();
            $context = [
                'earnings_date' => $earningsDate->toDateString(),
                'exit_deadline' => EarningsExitSchedule::exitDeadline($earningsDate, $hour)->toDateString(),
            ];

            if (in_array($phase, ['warning', 'auto'], true) && EarningsExitSchedule::isWarningDay($earningsDate, $today, $hour)) {
                $this->alertDispatcher->queue($position, AlertEventType::EarningsWarning, $context);
                $summary['warning']++;
            }

            if (in_array($phase, ['weekend', 'auto'], true) && EarningsExitSchedule::isWeekendReminderDay($earningsDate, $today, $hour)) {
                $this->alertDispatcher->queue($position, AlertEventType::EarningsActionRequired, [
                    ...$context,
                    'reminder' => 'tomorrow',
                ]);
                $summary['weekend']++;
            }

            if (in_array($phase, ['action', 'auto'], true) && EarningsExitSchedule::isActionDay($earningsDate, $today, $hour)) {
                $this->alertDispatcher->queue($position, AlertEventType::EarningsActionRequired, [
                    ...$context,
                    'reminder' => 'today',
                ]);
                $summary['action']++;
            }

            if (
                in_array($phase, ['final', 'auto'], true)
                && EarningsExitSchedule::isActionDay($earningsDate, $today, $hour)
                && self::requiresBeforeOpenExit($hour)
            ) {
                $this->alertDispatcher->queue($position, AlertEventType::EarningsFinalReminder, $context);
                $summary['final']++;
            }
        }

        return $summary;
    }

    private static function requiresBeforeOpenExit(?EarningsReleaseHour $hour): bool
    {
        return $hour !== EarningsReleaseHour::Amc;
    }
}
