<?php

namespace Tests\Unit;

use App\Support\SlPriceProximity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SlPriceProximityTest extends TestCase
{
    #[DataProvider('atrBasedColors')]
    public function test_color_uses_atr_buffer_when_available(float $close, float $sl, float $atr, string $expected): void
    {
        $this->assertSame($expected, SlPriceProximity::color($close, $sl, $atr));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: float, 3: string}>
     */
    public static function atrBasedColors(): array
    {
        return [
            'at or below sl' => [24.38, 24.38, 0.50, 'danger'],
            'critically tight' => [24.38, 24.21, 0.50, 'danger'],
            'getting tight' => [78.20, 76.10, 2.80, 'warning'],
            'comfortable cushion' => [80.00, 76.10, 2.80, 'success'],
        ];
    }

    #[DataProvider('percentageFallbackColors')]
    public function test_color_falls_back_to_percentage_without_atr(float $close, float $sl, string $expected): void
    {
        $this->assertSame($expected, SlPriceProximity::color($close, $sl));
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: string}>
     */
    public static function percentageFallbackColors(): array
    {
        return [
            'very close by percent' => [24.38, 24.28, 'danger'],
            'close by percent' => [100.00, 98.50, 'warning'],
            'comfortable by percent' => [100.00, 97.00, 'success'],
        ];
    }
}
