<?php

namespace Tests\Unit;

use App\Support\FreerideDisplay;
use PHPUnit\Framework\TestCase;

class FreerideDisplayTest extends TestCase
{
    public function test_gap_to_freeride_returns_percentage_and_dollars_when_sl_below_entry(): void
    {
        $gap = FreerideDisplay::gapToFreeride(70.0, 65.0, 10.0);

        $this->assertNotNull($gap);
        $this->assertEqualsWithDelta(7.142857, $gap['percentage'], 0.0001);
        $this->assertEqualsWithDelta(50.0, $gap['dollars'], 0.01);
    }

    public function test_gap_to_freeride_returns_null_when_sl_at_or_above_entry(): void
    {
        $this->assertNull(FreerideDisplay::gapToFreeride(70.0, 70.0, 10.0));
        $this->assertNull(FreerideDisplay::gapToFreeride(70.0, 75.0, 10.0));
    }

    public function test_distance_subtext_formats_percentage(): void
    {
        $gap = FreerideDisplay::gapToFreeride(233.0, 231.66, 10.0);

        $this->assertSame('Nog 0.58% tot Freeride', FreerideDisplay::distanceSubtext($gap));
    }

    public function test_distance_subtext_returns_null_without_gap(): void
    {
        $this->assertNull(FreerideDisplay::distanceSubtext(null));
    }
}
