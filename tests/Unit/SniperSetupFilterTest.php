<?php

namespace Tests\Unit;

use App\Support\SniperSetupFilter;
use PHPUnit\Framework\TestCase;

class SniperSetupFilterTest extends TestCase
{
    public function test_long_passes_tv_parity_band(): void
    {
        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 101.0,
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 40.0,
        ]));

        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 101.0,
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 55.0,
        ]));
    }

    public function test_long_rejects_outside_valstrik_or_rsi(): void
    {
        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 104.0,
            'sma10' => 104.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 50.0,
        ]));

        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 101.0,
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 56.0,
        ]));
    }

    public function test_short_uses_rsi_floor_40_like_tv_screener(): void
    {
        $this->assertSame('short', SniperSetupFilter::evaluate([
            'open' => 101.0,
            'close' => 100.0,
            'sma10' => 99.5,
            'sma20' => 100.5,
            'sma50' => 102.0,
            'rsi14' => 40.0,
        ]));

        $this->assertSame('short', SniperSetupFilter::evaluate([
            'open' => 101.0,
            'close' => 100.0,
            'sma10' => 99.5,
            'sma20' => 100.5,
            'sma50' => 102.0,
            'rsi14' => 60.0,
        ]));

        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 101.0,
            'close' => 100.0,
            'sma10' => 99.5,
            'sma20' => 100.5,
            'sma50' => 102.0,
            'rsi14' => 39.0,
        ]));
    }
}
