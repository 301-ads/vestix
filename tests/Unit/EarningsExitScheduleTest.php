<?php

namespace Tests\Unit;

use App\Enums\EarningsExitUrgency;
use App\Enums\EarningsReleaseHour;
use App\Support\EarningsExitSchedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EarningsExitScheduleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monday_earnings_has_friday_action_and_thursday_warning(): void
    {
        $earnings = Carbon::parse('2026-03-09', 'Europe/Amsterdam'); // Monday

        $this->assertSame('2026-03-06', EarningsExitSchedule::actionDate($earnings)->toDateString());
        $this->assertSame('2026-03-05', EarningsExitSchedule::warningDate($earnings)->toDateString());
        $this->assertTrue(EarningsExitSchedule::isActionDay($earnings, Carbon::parse('2026-03-06', 'Europe/Amsterdam')));
        $this->assertTrue(EarningsExitSchedule::isWarningDay($earnings, Carbon::parse('2026-03-05', 'Europe/Amsterdam')));
    }

    public function test_tuesday_earnings_skips_weekend_for_warning(): void
    {
        $earnings = Carbon::parse('2026-03-10', 'Europe/Amsterdam'); // Tuesday

        $this->assertSame('2026-03-09', EarningsExitSchedule::actionDate($earnings)->toDateString());
        $this->assertSame('2026-03-06', EarningsExitSchedule::warningDate($earnings)->toDateString());
    }

    public function test_wednesday_earnings_has_tuesday_action_and_monday_warning(): void
    {
        $earnings = Carbon::parse('2026-03-11', 'Europe/Amsterdam'); // Wednesday

        $this->assertSame('2026-03-10', EarningsExitSchedule::actionDate($earnings)->toDateString());
        $this->assertSame('2026-03-09', EarningsExitSchedule::warningDate($earnings)->toDateString());
    }

    public function test_days_until_earnings_uses_calendar_days(): void
    {
        $earnings = Carbon::parse('2026-03-09', 'Europe/Amsterdam');
        $today = Carbon::parse('2026-03-05', 'Europe/Amsterdam');

        $this->assertSame(4, EarningsExitSchedule::daysUntilEarnings($earnings, $today));
    }

    public function test_urgency_mapping_for_monday_earnings(): void
    {
        $earnings = Carbon::parse('2026-03-09', 'Europe/Amsterdam');

        $this->assertSame(
            EarningsExitUrgency::Prepare,
            EarningsExitSchedule::urgency($earnings, Carbon::parse('2026-03-05', 'Europe/Amsterdam')),
        );
        $this->assertSame(
            EarningsExitUrgency::ExitToday,
            EarningsExitSchedule::urgency($earnings, Carbon::parse('2026-03-06', 'Europe/Amsterdam')),
        );
        $this->assertSame(
            EarningsExitUrgency::Overdue,
            EarningsExitSchedule::urgency($earnings, Carbon::parse('2026-03-08', 'Europe/Amsterdam')),
        );
        $this->assertNull(EarningsExitSchedule::urgency($earnings, Carbon::parse('2026-03-04', 'Europe/Amsterdam')));
    }

    public function test_amc_monday_earnings_has_monday_action_and_friday_warning(): void
    {
        $earnings = Carbon::parse('2026-03-09', 'Europe/Amsterdam'); // Monday

        $this->assertSame(
            '2026-03-09',
            EarningsExitSchedule::exitDeadline($earnings, EarningsReleaseHour::Amc)->toDateString(),
        );
        $this->assertSame(
            '2026-03-09',
            EarningsExitSchedule::actionDate($earnings, EarningsReleaseHour::Amc)->toDateString(),
        );
        $this->assertSame(
            '2026-03-06',
            EarningsExitSchedule::warningDate($earnings, EarningsReleaseHour::Amc)->toDateString(),
        );
        $this->assertTrue(EarningsExitSchedule::isActionDay(
            $earnings,
            Carbon::parse('2026-03-09', 'Europe/Amsterdam'),
            EarningsReleaseHour::Amc,
        ));
        $this->assertTrue(EarningsExitSchedule::isWarningDay(
            $earnings,
            Carbon::parse('2026-03-06', 'Europe/Amsterdam'),
            EarningsReleaseHour::Amc,
        ));
    }

    public function test_amc_urgency_mapping_for_monday_earnings(): void
    {
        $earnings = Carbon::parse('2026-03-09', 'Europe/Amsterdam');

        $this->assertSame(
            EarningsExitUrgency::Prepare,
            EarningsExitSchedule::urgency(
                $earnings,
                Carbon::parse('2026-03-06', 'Europe/Amsterdam'),
                EarningsReleaseHour::Amc,
            ),
        );
        $this->assertSame(
            EarningsExitUrgency::ExitToday,
            EarningsExitSchedule::urgency(
                $earnings,
                Carbon::parse('2026-03-09', 'Europe/Amsterdam'),
                EarningsReleaseHour::Amc,
            ),
        );
        $this->assertNull(EarningsExitSchedule::urgency(
            $earnings,
            Carbon::parse('2026-03-10', 'Europe/Amsterdam'),
            EarningsReleaseHour::Amc,
        ));
    }
}
