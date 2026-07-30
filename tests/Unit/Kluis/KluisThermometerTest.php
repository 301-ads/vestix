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

    public function test_price_line_shows_usd_proxy_not_eur_display_ticker(): void
    {
        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);

        $reading = (new KluisThermometer)->readingFromPrices(
            close: 152.22,
            sma200: 146.60,
            ticker: 'VWCE',
            settings: $settings,
            resolvedSymbol: 'VT',
        );

        $this->assertTrue($reading->usesProxy());
        $this->assertSame('$', $reading->priceCurrencySymbol());
        $this->assertSame(
            'VWCE via VT · koers $152,22 · SMA-200 $146,60',
            $reading->priceLine(),
        );
    }

    public function test_price_line_keeps_euro_for_eu_symbol(): void
    {
        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);

        $reading = (new KluisThermometer)->readingFromPrices(
            close: 161.76,
            sma200: 155.00,
            ticker: 'VWCE',
            settings: $settings,
            resolvedSymbol: 'VWCE.DE',
        );

        $this->assertTrue($reading->usesProxy());
        $this->assertSame('€', $reading->priceCurrencySymbol());
        $this->assertSame(
            'VWCE via VWCE.DE · koers €161,76 · SMA-200 €155,00',
            $reading->priceLine(),
        );
    }
}
