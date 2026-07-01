<?php

namespace Tests\Unit;

use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UsMarketSessionNextTradingDayTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_next_trading_day_from_weekday(): void
    {
        $thursday = Carbon::parse('2026-07-02', 'Europe/Amsterdam');

        $this->assertSame(
            '2026-07-03',
            UsMarketSession::nextTradingDay($thursday)->toDateString(),
        );
    }

    public function test_next_trading_day_from_friday_skips_weekend(): void
    {
        $friday = Carbon::parse('2026-07-03', 'Europe/Amsterdam');

        $this->assertSame(
            '2026-07-06',
            UsMarketSession::nextTradingDay($friday)->toDateString(),
        );
    }
}
