<?php

namespace Tests\Unit;

use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UsMarketSessionTest extends TestCase
{
    public function test_monday_evening_expects_monday_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        $this->assertSame(
            '2026-06-15',
            UsMarketSession::expectedLastCompletedSessionDate()->toDateString(),
        );
    }

    public function test_monday_morning_expects_previous_friday_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'America/New_York'));

        $this->assertSame(
            '2026-06-12',
            UsMarketSession::expectedLastCompletedSessionDate()->toDateString(),
        );
    }

    public function test_after_market_close_always_needs_latest_session_quote(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        $this->assertTrue(UsMarketSession::isAfterMarketClose());
        $this->assertTrue(UsMarketSession::needsLatestSessionQuote('2026-06-15'));
    }

    public function test_friday_bar_is_stale_on_monday_evening(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        $this->assertTrue(UsMarketSession::isBarStale('2026-06-12'));
        $this->assertFalse(UsMarketSession::isBarStale('2026-06-15'));
        $this->assertTrue(UsMarketSession::needsLatestSessionQuote('2026-06-12'));
    }
}
