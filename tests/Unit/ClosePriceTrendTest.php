<?php

namespace Tests\Unit;

use App\Support\ClosePriceTrend;
use Tests\TestCase;

class ClosePriceTrendTest extends TestCase
{
    public function test_uses_second_to_last_close_when_current_matches_last_stored_session(): void
    {
        $trend = ClosePriceTrend::resolveDayChange(78.20, [76.50, 77.00, 78.20]);

        $this->assertNotNull($trend);
        $this->assertSame('+1.56% t.o.v. slotkoers', $trend['description']);
        $this->assertSame('success', $trend['color']);
    }

    public function test_uses_last_stored_close_when_current_price_is_ahead_of_history(): void
    {
        $trend = ClosePriceTrend::resolveDayChange(121.00, [100.00, 110.00]);

        $this->assertNotNull($trend);
        $this->assertSame('+10.00% t.o.v. slotkoers', $trend['description']);
    }

    public function test_returns_null_when_history_is_empty(): void
    {
        $this->assertNull(ClosePriceTrend::resolveDayChange(100.00, []));
    }
}
