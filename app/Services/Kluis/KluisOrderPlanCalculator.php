<?php

namespace App\Services\Kluis;

use App\Enums\KluisClimate;
use App\Models\VaultSetting;
use App\Support\Kluis\KluisOrderPlan;
use App\Support\Kluis\KluisThermometerReading;

class KluisOrderPlanCalculator
{
    public function calculate(
        VaultSetting $settings,
        float $budget,
        KluisThermometerReading $reading,
        ?float $valuationPrice = null,
    ): KluisOrderPlan {
        $budget = max(0.0, round($budget, 2));
        $dryPowder = max(0.0, (float) $settings->dry_powder_balance);

        [$etfAmount, $dryPowderDelta] = match ($reading->climate) {
            KluisClimate::Overheat => $this->overheatPlan($budget, $settings),
            KluisClimate::Neutral => [$budget, 0.0],
            KluisClimate::Dip => $this->deployPlan(
                $budget,
                $dryPowder,
                (float) $settings->dip_dry_powder_fraction,
            ),
            KluisClimate::Crash => $this->deployPlan(
                $budget,
                $dryPowder,
                (float) $settings->crash_dry_powder_fraction,
            ),
        };

        $etfAmount = round($etfAmount, 2);
        $dryPowderDelta = round($dryPowderDelta, 2);
        $dryPowderAfter = round(max(0.0, $dryPowder + $dryPowderDelta), 2);
        $referencePrice = $valuationPrice !== null && $valuationPrice > 0
            ? $valuationPrice
            : ($reading->close > 0 ? $reading->close : null);
        $suggestedShares = $referencePrice !== null && $etfAmount > 0
            ? round($etfAmount / $referencePrice, 4)
            : null;

        return new KluisOrderPlan(
            climate: $reading->climate,
            budgetInput: $budget,
            etfAmount: $etfAmount,
            dryPowderDelta: $dryPowderDelta,
            dryPowderAfter: $dryPowderAfter,
            message: $reading->message(),
            suggestedShares: $suggestedShares,
            referencePrice: $referencePrice,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function overheatPlan(float $budget, VaultSetting $settings): array
    {
        $investFraction = min(1.0, max(0.0, (float) $settings->overheat_invest_fraction));
        $etfAmount = round($budget * $investFraction, 2);
        $toPowder = round($budget - $etfAmount, 2);

        return [$etfAmount, $toPowder];
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function deployPlan(float $budget, float $dryPowder, float $fraction): array
    {
        $fraction = min(1.0, max(0.0, $fraction));
        $fromPowder = round($dryPowder * $fraction, 2);

        return [$budget + $fromPowder, -$fromPowder];
    }
}
