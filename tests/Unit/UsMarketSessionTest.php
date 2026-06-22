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

    public function test_after_market_close_does_not_need_quote_when_bar_is_current(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        $this->assertTrue(UsMarketSession::isAfterMarketClose());
        $this->assertFalse(UsMarketSession::needsLatestSessionQuote('2026-06-15'));
    }

    public function test_friday_bar_is_stale_on_monday_evening(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 18:53:00', 'America/New_York'));

        $this->assertTrue(UsMarketSession::isBarStale('2026-06-12'));
        $this->assertFalse(UsMarketSession::isBarStale('2026-06-15'));
        $this->assertTrue(UsMarketSession::needsLatestSessionQuote('2026-06-12'));
    }

    public function test_premarket_window_on_weekday_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00', 'America/New_York'));

        $this->assertTrue(UsMarketSession::isUsTradingDay());
        $this->assertTrue(UsMarketSession::isPremarketWindow());
    }

    public function test_premarket_window_is_false_during_regular_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 11:00:00', 'America/New_York'));

        $this->assertFalse(UsMarketSession::isPremarketWindow());
    }

    public function test_gatekeeper_window_at_fourteen_thirty_amsterdam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 14:30:00', 'Europe/Amsterdam'));

        $this->assertTrue(UsMarketSession::isGatekeeperWindow());
    }

    public function test_gatekeeper_window_is_false_before_window_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 14:00:00', 'Europe/Amsterdam'));

        $this->assertFalse(UsMarketSession::isGatekeeperWindow());
    }

    public function test_gatekeeper_window_is_true_at_window_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 14:25:00', 'Europe/Amsterdam'));

        $this->assertTrue(UsMarketSession::isGatekeeperWindow());
    }
}
