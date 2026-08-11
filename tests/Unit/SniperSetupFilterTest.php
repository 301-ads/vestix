<?php

namespace Tests\Unit;

use App\Support\SniperSetupFilter;
use Tests\TestCase;

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

    public function test_long_rejects_flat_or_declining_sma20_five_day_slope(): void
    {
        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 101.0,
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma20FiveDaysAgo' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 45.0,
        ]));

        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 101.0,
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma20FiveDaysAgo' => 100.5,
            'sma50' => 98.0,
            'rsi14' => 45.0,
        ]));

        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 100.0,
            'close' => 101.0,
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma20FiveDaysAgo' => 99.5,
            'sma50' => 98.0,
            'rsi14' => 45.0,
        ]));
    }

    public function test_long_rejects_sloopkogel_open_below_sma_with_previous_close_below(): void
    {
        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 41.94,
            'high' => 43.01,
            'low' => 41.88,
            'close' => 42.88,
            'previousClose' => 41.50,
            'sma10' => 43.20,
            'sma20' => 42.65,
            'sma20FiveDaysAgo' => 41.95,
            'sma50' => 41.24,
            'rsi14' => 53.0,
        ]));

        // Open boven SMA — legitieme landing.
        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 42.70,
            'high' => 42.95,
            'low' => 42.60,
            'close' => 42.88,
            'previousClose' => 41.50,
            'sma10' => 43.20,
            'sma20' => 42.65,
            'sma20FiveDaysAgo' => 41.95,
            'sma50' => 41.24,
            'rsi14' => 53.0,
        ]));

        // Open onder SMA, maar previous close erboven — escape hatch.
        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 41.94,
            'high' => 43.01,
            'low' => 41.88,
            'close' => 42.88,
            'previousClose' => 42.80,
            'sma10' => 43.20,
            'sma20' => 42.65,
            'sma20FiveDaysAgo' => 41.95,
            'sma50' => 41.24,
            'rsi14' => 53.0,
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
