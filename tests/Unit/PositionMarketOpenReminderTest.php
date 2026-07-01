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

    public function test_schedule_market_open_reminder_sets_next_trading_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 10:00:00', 'Europe/Amsterdam'));

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
        ]);

        $scout->clearMarketOpenReminder();

        $scout->refresh();

        $this->assertNull($scout->market_open_reminder_on);
    }
}
