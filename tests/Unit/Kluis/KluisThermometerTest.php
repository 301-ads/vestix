<?php

namespace Tests\Unit\Kluis;

use App\Enums\KluisClimate;
use App\Models\User;
use App\Models\VaultSetting;
use App\Services\Kluis\KluisThermometer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KluisThermometerTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_four_climates(): void
    {
        $thermometer = new KluisThermometer;

        $this->assertSame(KluisClimate::Overheat, $thermometer->classify(10.01));
        $this->assertSame(KluisClimate::Neutral, $thermometer->classify(10.0));
        $this->assertSame(KluisClimate::Neutral, $thermometer->classify(0.0));
        $this->assertSame(KluisClimate::Dip, $thermometer->classify(-0.01));
        $this->assertSame(KluisClimate::Dip, $thermometer->classify(-10.0));
        $this->assertSame(KluisClimate::Crash, $thermometer->classify(-10.01));
    }

    public function test_reading_from_prices_uses_settings_thresholds(): void
    {
        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->overheat_threshold_pct = 8;
        $settings->crash_threshold_pct = 12;

        $reading = (new KluisThermometer)->readingFromPrices(
            close: 109,
            sma200: 100,
            ticker: 'VWCE',
            settings: $settings,
        );

        $this->assertSame(KluisClimate::Overheat, $reading->climate);
        $this->assertEqualsWithDelta(9.0, $reading->deviationPct, 0.01);
    }
}
