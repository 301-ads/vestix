<?php

namespace App\Support;

use App\Enums\TradeDirection;

class PositionSizing
{
    /**
     * @return array<string, string>
     */
    public static function riskPercentOptions(): array
    {
        return [
            '1' => '1%',
            '1.5' => '1.5%',
            '2' => '2%',
            '2.5' => '2.5%',
            '3' => '3%',
        ];
    }

    /**
     * Map a stored risk percent (e.g. "1.00") to a ToggleButtons option key (e.g. "1").
     */
    public static function normalizeRiskPercentOptionKey(float|int|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    public static function riskBudgetFromPercent(float $bankroll, float $percent): float
    {
        return $bankroll * ($percent / 100);
    }

    public static function riskPerShare(
        float $entry,
        ?float $stopLoss,
        TradeDirection|string|null $direction = TradeDirection::Long,
    ): ?float {
        if ($stopLoss === null) {
            return null;
        }

        $direction = self::normalizeDirection($direction);

        $risk = $direction === TradeDirection::Short
            ? round($stopLoss - $entry, 2)
            : round($entry - $stopLoss, 2);

        if ($risk <= 0) {
            return null;
        }

        return $risk;
    }

    public static function targetPrice(
        float $entry,
        float $riskPerShare,
        float $rewardRisk,
        TradeDirection|string|null $direction = TradeDirection::Long,
    ): float {
        $direction = self::normalizeDirection($direction);

        if ($direction === TradeDirection::Short) {
            return round($entry - ($rewardRisk * $riskPerShare), 2);
        }

        return round($entry + ($rewardRisk * $riskPerShare), 2);
    }

    public static function quantityFromRiskBudget(
        float $riskBudget,
        float $entry,
        ?float $stopLoss,
        TradeDirection|string|null $direction = TradeDirection::Long,
    ): ?int {
        if ($riskBudget <= 0) {
            return null;
        }

        $riskPerShare = self::riskPerShare($entry, $stopLoss, $direction);

        if ($riskPerShare === null) {
            return null;
        }

        return (int) floor($riskBudget / $riskPerShare);
    }

    public static function quantityFromInvestment(float $investment, float $entry): ?int
    {
        if ($investment <= 0 || $entry <= 0) {
            return null;
        }

        $entryCents = (int) round(round($entry, 2) * 100);
        $investmentCents = (int) round($investment * 100);

        if ($entryCents <= 0) {
            return null;
        }

        return intdiv($investmentCents, $entryCents);
    }

    public static function plannedRiskTotal(
        int $quantity,
        float $entry,
        ?float $stopLoss,
        TradeDirection|string|null $direction = TradeDirection::Long,
    ): ?float {
        if ($quantity < 1) {
            return null;
        }

        $riskPerShare = self::riskPerShare($entry, $stopLoss, $direction);

        if ($riskPerShare === null) {
            return null;
        }

        return $riskPerShare * $quantity;
    }

    public static function resolveRiskLimit(?float $positionRiskBudget, ?float $userDefault): ?float
    {
        if ($positionRiskBudget !== null && $positionRiskBudget > 0) {
            return $positionRiskBudget;
        }

        if ($userDefault !== null && $userDefault > 0) {
            return $userDefault;
        }

        return null;
    }

    public static function exceedsRiskLimit(float $plannedRisk, ?float $riskLimit): bool
    {
        if ($riskLimit === null || $riskLimit <= 0) {
            return false;
        }

        return $plannedRisk > $riskLimit;
    }

    public static function resolveRiskLimitFromProfile(?float $bankroll, ?float $defaultRiskPercent): ?float
    {
        if ($bankroll === null || $bankroll <= 0 || $defaultRiskPercent === null || $defaultRiskPercent <= 0) {
            return null;
        }

        return self::riskBudgetFromPercent($bankroll, $defaultRiskPercent);
    }

    public static function riskAsPercentOfBankroll(float $plannedRisk, float $bankroll): float
    {
        if ($bankroll <= 0) {
            return 0.0;
        }

        return ($plannedRisk / $bankroll) * 100;
    }

    public static function overLimitByPercentPoints(float $plannedRiskPercent, float $limitPercent): float
    {
        return max(0.0, $plannedRiskPercent - $limitPercent);
    }

    private static function normalizeDirection(TradeDirection|string|null $direction): TradeDirection
    {
        if ($direction instanceof TradeDirection) {
            return $direction;
        }

        if (is_string($direction) && $direction === TradeDirection::Short->value) {
            return TradeDirection::Short;
        }

        return TradeDirection::Long;
    }
}
