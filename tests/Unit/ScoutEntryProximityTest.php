<?php

namespace Tests\Unit;

use App\Support\ScoutEntryProximity;
use Tests\TestCase;

class ScoutEntryProximityTest extends TestCase
{
    public function test_is_near_entry_within_margin(): void
    {
        $this->assertTrue(ScoutEntryProximity::isNearEntry(100.00, 100.50, 0.5));
    }

    public function test_is_not_near_entry_outside_margin(): void
    {
        $this->assertFalse(ScoutEntryProximity::isNearEntry(95.00, 100.00, 0.5));
    }

    public function test_is_not_near_entry_with_zero_entry(): void
    {
        $this->assertFalse(ScoutEntryProximity::isNearEntry(100.00, 0.0, 0.5));
    }
}
