<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Support\UsMarketSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PositionMarketOpenReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_schedule_market_open_reminder_same_day_when_before_reminder_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 14:00:00', 'Europe/Amsterdam'));

        $scout = Position::factory()->scout()->create();

        $scout->scheduleMarketOpenReminder();

        $scout->refresh();

        $this->assertSame('2026-07-01', $scout->market_open_reminder_on?->toDateString());
    }

    public function test_schedule_market_open_reminder_next_day_when_after_reminder_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 16:00:00', 'Europe/Amsterdam'));

        $scout = Position::factory()->scout()->create();

        $scout->scheduleMarketOpenReminder();

        $scout->refresh();

        $this->assertSame(
            UsMarketSession::nextTradingDay(Carbon::parse('2026-07-01', 'Europe/Amsterdam'))->toDateString(),
            $scout->market_open_reminder_on?->toDateString(),
        );
    }

    public function test_schedule_market_open_reminder_sets_next_trading_day_on_weekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 10:00:00', 'Europe/Amsterdam'));

        $scout = Position::factory()->scout()->create();

        $scout->scheduleMarketOpenReminder();

        $scout->refresh();

        $this->assertSame(
            UsMarketSession::nextTradingDay(Carbon::today('Europe/Amsterdam'))->toDateString(),
            $scout->market_open_reminder_on?->toDateString(),
        );
    }

    public function test_clear_market_open_reminder(): void
    {
        $scout = Position::factory()->scout()->create([
            'market_open_reminder_on' => '2026-07-06',
            'order_plan_excluded_on' => '2026-07-06',
        ]);

        $scout->clearMarketOpenReminder();

        $scout->refresh();

        $this->assertNull($scout->market_open_reminder_on);
        $this->assertNull($scout->order_plan_excluded_on);
    }
}
