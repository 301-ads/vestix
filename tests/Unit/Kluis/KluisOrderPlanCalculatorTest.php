<?php

namespace Tests\Unit\Kluis;

use App\Enums\KluisClimate;
use App\Models\User;
use App\Models\VaultSetting;
use App\Services\Kluis\KluisOrderPlanCalculator;
use App\Support\Kluis\KluisThermometerReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KluisOrderPlanCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $overrides = []): VaultSetting
    {
        $user = User::factory()->create();
        $settings = VaultSetting::defaultsFor($user);
        $settings->fill($overrides);
        $settings->save();

        return $settings->fresh();
    }

    private function reading(KluisClimate $climate, float $deviation = 0.0): KluisThermometerReading
    {
        return new KluisThermometerReading(
            climate: $climate,
            deviationPct: $deviation,
            close: 100,
            sma200: 100,
            ticker: 'VWCE',
        );
    }

    public function test_overheat_splits_budget_into_etf_and_dry_powder(): void
    {
        $settings = $this->settings(['dry_powder_balance' => 1000]);
        $plan = app(KluisOrderPlanCalculator::class)->calculate(
            $settings,
            10000,
            $this->reading(KluisClimate::Overheat, 12),
        );

        $this->assertSame(5000.0, $plan->etfAmount);
        $this->assertSame(5000.0, $plan->dryPowderDelta);
        $this->assertSame(6000.0, $plan->dryPowderAfter);
    }

    public function test_neutral_invests_full_budget(): void
    {
        $settings = $this->settings(['dry_powder_balance' => 2000]);
        $plan = app(KluisOrderPlanCalculator::class)->calculate(
            $settings,
            10000,
            $this->reading(KluisClimate::Neutral, 3),
        );

        $this->assertSame(10000.0, $plan->etfAmount);
        $this->assertSame(0.0, $plan->dryPowderDelta);
        $this->assertSame(2000.0, $plan->dryPowderAfter);
        $this->assertSame(100.0, $plan->suggestedShares);
        $this->assertSame(100.0, $plan->referencePrice);
    }

    public function test_dip_deploys_quarter_of_dry_powder(): void
    {
        $settings = $this->settings(['dry_powder_balance' => 4000]);
        $plan = app(KluisOrderPlanCalculator::class)->calculate(
            $settings,
            10000,
            $this->reading(KluisClimate::Dip, -5),
        );

        $this->assertSame(11000.0, $plan->etfAmount);
        $this->assertSame(-1000.0, $plan->dryPowderDelta);
        $this->assertSame(3000.0, $plan->dryPowderAfter);
    }

    public function test_crash_deploys_half_of_dry_powder(): void
    {
        $settings = $this->settings(['dry_powder_balance' => 4000]);
        $plan = app(KluisOrderPlanCalculator::class)->calculate(
            $settings,
            10000,
            $this->reading(KluisClimate::Crash, -15),
        );

        $this->assertSame(12000.0, $plan->etfAmount);
        $this->assertSame(-2000.0, $plan->dryPowderDelta);
        $this->assertSame(2000.0, $plan->dryPowderAfter);
    }

    public function test_dip_with_empty_dry_powder_only_uses_budget(): void
    {
        $settings = $this->settings(['dry_powder_balance' => 0]);
        $plan = app(KluisOrderPlanCalculator::class)->calculate(
            $settings,
            10000,
            $this->reading(KluisClimate::Dip, -3),
        );

        $this->assertSame(10000.0, $plan->etfAmount);
        $this->assertSame(0.0, $plan->dryPowderDelta);
        $this->assertSame(0.0, $plan->dryPowderAfter);
    }
}
