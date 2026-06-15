<?php

namespace Tests\Support;

use Illuminate\Support\Carbon;

class MarketDataTestTime
{
    public static function freezeBeforeUsMarketClose(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00', 'America/New_York'));
    }

    public static function reset(): void
    {
        Carbon::setTestNow();
    }
}
