<?php

namespace Tests\Unit;

use App\Support\TechnicalIndicators;
use Tests\TestCase;

class TechnicalIndicatorsTest extends TestCase
{
    public function test_sma_returns_average_of_last_period_values(): void
    {
        $this->assertEqualsWithDelta(4.0, TechnicalIndicators::sma([1, 2, 3, 4, 5], 3), 0.0001);
    }

    public function test_sma_returns_null_when_insufficient_values(): void
    {
        $this->assertNull(TechnicalIndicators::sma([1, 2], 3));
    }

    public function test_sma_at_offset_returns_value_five_bars_ago(): void
    {
        $values = [10, 11, 12, 13, 14, 15, 16, 17];

        $this->assertEqualsWithDelta(16.0, TechnicalIndicators::smaAtOffset($values, 3, 0), 0.0001);
        $this->assertEqualsWithDelta(11.0, TechnicalIndicators::smaAtOffset($values, 3, 5), 0.0001);
    }

    public function test_wilder_rsi_on_flat_series_returns_one_hundred(): void
    {
        $closes = array_fill(0, 20, 100.0);

        $this->assertEqualsWithDelta(100.0, TechnicalIndicators::wilderRsi($closes, 14), 0.0001);
    }

    public function test_wilder_rsi_on_uptrend_is_above_fifty(): void
    {
        $closes = [];

        for ($day = 0; $day < 30; $day++) {
            $closes[] = 100.0 + $day;
        }

        $rsi = TechnicalIndicators::wilderRsi($closes, 14);

        $this->assertNotNull($rsi);
        $this->assertGreaterThan(50.0, $rsi);
    }

    public function test_wilder_atr_on_constant_range(): void
    {
        $bars = [];

        for ($day = 0; $day < 20; $day++) {
            $bars[] = [
                'high' => 102.0,
                'low' => 98.0,
                'close' => 100.0,
            ];
        }

        $atr = TechnicalIndicators::wilderAtr($bars, 14);

        $this->assertNotNull($atr);
        $this->assertEqualsWithDelta(4.0, $atr, 0.0001);
    }

    public function test_wilder_atr_returns_null_when_insufficient_bars(): void
    {
        $bars = [
            ['high' => 102.0, 'low' => 98.0, 'close' => 100.0],
            ['high' => 103.0, 'low' => 99.0, 'close' => 101.0],
        ];

        $this->assertNull(TechnicalIndicators::wilderAtr($bars, 14));
    }
}
