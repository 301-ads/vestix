<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\Position;
use App\Support\EarningsExitSchedule;
use Illuminate\Support\Carbon;

class EarningsExitAlertService
{
    public function __construct(
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * @return array{warning: int, action: int}
     */
    public function run(string $phase, ?Carbon $today = null): array
    {
        $today ??= Carbon::today('Europe/Amsterdam');
        $summary = ['warning' => 0, 'action' => 0];

        $positions = Position::query()
            ->open()
            ->with('asset')
            ->get()
            ->filter(fn (Position $position): bool => $position->effectiveEarningsDate() !== null);

        foreach ($positions as $position) {
            $earningsDate = $position->effectiveEarningsDate();

            if ($earningsDate === null) {
                continue;
            }

            $context = [
                'earnings_date' => $earningsDate->toDateString(),
            ];

            $hour = $position->asset?->effectiveEarningsHour();

            if (in_array($phase, ['warning', 'auto'], true) && EarningsExitSchedule::isWarningDay($earningsDate, $today, $hour)) {
                $this->alertDispatcher->queue($position, AlertEventType::EarningsWarning, $context);
                $summary['warning']++;
            }

            if (in_array($phase, ['action', 'auto'], true) && EarningsExitSchedule::isActionDay($earningsDate, $today, $hour)) {
                $this->alertDispatcher->queue($position, AlertEventType::EarningsActionRequired, $context);
                $summary['action']++;
            }
        }

        return $summary;
    }
}
