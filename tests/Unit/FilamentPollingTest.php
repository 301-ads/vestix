<?php

namespace Tests\Unit;

use App\Support\FilamentPolling;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FilamentPollingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_live_interval_during_intraday_watch_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'America/New_York'));

        $this->assertTrue(UsMarketSession::isIntradayTargetWatchWindow());
        $this->assertSame('10s', FilamentPolling::interval());
    }

    public function test_idle_interval_outside_intraday_watch_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 20:00:00', 'America/New_York'));

        $this->assertFalse(UsMarketSession::isIntradayTargetWatchWindow());
        $this->assertSame('60s', FilamentPolling::interval());
    }

    public function test_interval_constant_remains_live_default(): void
    {
        $this->assertSame('10s', FilamentPolling::INTERVAL);
    }
}
