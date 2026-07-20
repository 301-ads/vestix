<?php

namespace Tests\Feature\Console;

use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ScheduleRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_watch_target_prices_runs_hourly_at_five_past_within_intraday_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'America/New_York'));

        $event = collect(Schedule::events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'vestix:watch-target-prices'));

        $this->assertNotNull($event);
        $this->assertSame('5 * * * 1-5', $event->expression);
        $this->assertTrue($event->timezone === 'America/New_York' || $event->timezone?->getName() === 'America/New_York');
        $this->assertTrue($event->filtersPass(app()));
    }

    public function test_watch_target_prices_is_filtered_outside_intraday_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'America/New_York'));

        $event = collect(Schedule::events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'vestix:watch-target-prices'));

        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass(app()));
        $this->assertFalse(UsMarketSession::isIntradayTargetWatchWindow());
    }

    public function test_bankroll_update_reminders_scheduled_on_saturday_morning(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'vestix:bankroll-update-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 10 * * 6', $event->expression);
        $this->assertTrue($event->timezone === 'Europe/Amsterdam' || $event->timezone?->getName() === 'Europe/Amsterdam');
    }

    public function test_ibkr_sync_scheduled_weekdays_after_us_close(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($scheduledEvent): bool => str_contains((string) $scheduledEvent->command, 'vestix:sync-ibkr'));

        $this->assertNotNull($event);
        $this->assertSame('45 22 * * 1-5', $event->expression);
        $this->assertTrue($event->timezone === 'Europe/Amsterdam' || $event->timezone?->getName() === 'Europe/Amsterdam');
    }
}
