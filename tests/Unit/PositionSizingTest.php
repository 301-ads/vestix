<?php

namespace Tests\Unit;

use App\Support\PositionSizing;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PositionSizingTest extends TestCase
{
    public function test_risk_budget_from_percent_revolut_example(): void
    {
        $this->assertEqualsWithDelta(109.2746, PositionSizing::riskBudgetFromPercent(10927.46, 1.0), 0.001);
    }

    public function test_percent_sizing_revolut_scenario_quantity(): void
    {
        $riskBudget = PositionSizing::riskBudgetFromPercent(10927.46, 1.0);
        $quantity = PositionSizing::quantityFromRiskBudget($riskBudget, 231.48, 222.01);

        $this->assertSame(11, $quantity);
    }

    public function test_risk_budget_from_percent_at_two_percent(): void
    {
        $this->assertEqualsWithDelta(218.5492, PositionSizing::riskBudgetFromPercent(10927.46, 2.0), 0.001);
    }

    public function test_quantity_from_risk_budget_example(): void
    {
        $this->assertSame(96, PositionSizing::quantityFromRiskBudget(50.0, 25.22, 24.70));
    }

    public function test_quantity_returns_null_when_stop_loss_missing(): void
    {
        $this->assertNull(PositionSizing::quantityFromRiskBudget(50.0, 25.22, null));
    }

    public function test_quantity_returns_null_when_entry_at_or_below_stop_loss(): void
    {
        $this->assertNull(PositionSizing::quantityFromRiskBudget(50.0, 24.70, 24.70));
        $this->assertNull(PositionSizing::quantityFromRiskBudget(50.0, 24.00, 24.70));
    }

    public function test_quantity_returns_null_when_risk_budget_is_zero_or_negative(): void
    {
        $this->assertNull(PositionSizing::quantityFromRiskBudget(0.0, 25.22, 24.70));
        $this->assertNull(PositionSizing::quantityFromRiskBudget(-10.0, 25.22, 24.70));
    }

    #[DataProvider('floorCasesProvider')]
    public function test_quantity_floors_to_whole_shares(float $riskBudget, float $entry, float $stopLoss, int $expected): void
    {
        $this->assertSame($expected, PositionSizing::quantityFromRiskBudget($riskBudget, $entry, $stopLoss));
    }

    public function test_risk_percent_options(): void
    {
        $this->assertSame([
            '1' => '1%',
            '1.5' => '1.5%',
            '2' => '2%',
            '2.5' => '2.5%',
            '3' => '3%',
        ], PositionSizing::riskPercentOptions());
    }

    public function test_normalize_risk_percent_option_key(): void
    {
        $this->assertSame('1', PositionSizing::normalizeRiskPercentOptionKey('1.00'));
        $this->assertSame('1.5', PositionSizing::normalizeRiskPercentOptionKey(1.5));
        $this->assertSame('2', PositionSizing::normalizeRiskPercentOptionKey(2));
        $this->assertNull(PositionSizing::normalizeRiskPercentOptionKey(null));
    }

    public function test_resolve_risk_limit_from_profile(): void
    {
        $this->assertEqualsWithDelta(109.42, PositionSizing::resolveRiskLimitFromProfile(10942.0, 1.0), 0.001);
        $this->assertNull(PositionSizing::resolveRiskLimitFromProfile(null, 1.0));
        $this->assertNull(PositionSizing::resolveRiskLimitFromProfile(10942.0, null));
    }

    public function test_risk_as_percent_of_bankroll(): void
    {
        $this->assertEqualsWithDelta(1.28, PositionSizing::riskAsPercentOfBankroll(140.0, 10942.0), 0.01);
        $this->assertEqualsWithDelta(0.41, PositionSizing::riskAsPercentOfBankroll(45.0, 10942.0), 0.01);
    }

    public function test_over_limit_by_percent_points(): void
    {
        $this->assertEqualsWithDelta(0.3, PositionSizing::overLimitByPercentPoints(1.3, 1.0), 0.001);
        $this->assertEqualsWithDelta(0.0, PositionSizing::overLimitByPercentPoints(0.4, 1.0), 0.001);
    }

    /**
     * @return array<string, array{float, float, float, int}>
     */
    public static function floorCasesProvider(): array
    {
        return [
            'exact division' => [39.0, 80.0, 76.10, 10],
            'floor partial share' => [39.5, 80.0, 76.10, 10],
            'single share minimum' => [3.90, 80.0, 76.10, 1],
        ];
    }
}
