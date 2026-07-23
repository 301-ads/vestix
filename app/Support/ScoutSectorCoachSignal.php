<?php

namespace App\Support;

use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\User;
use App\Services\PortfolioRiskCoachService;

final class ScoutSectorCoachSignal
{
    public const string ICON = 'heroicon-o-academic-cap';

    /** @var array<int, array<string, array{long: int, short: int}>> */
    private static array $riskOnCountsByUser = [];

    public static function clearCache(): void
    {
        self::$riskOnCountsByUser = [];
    }

    /**
     * @return array{state: 'interesting'|'full', icon: string, color: string, tooltip: string}|null
     */
    public static function for(?User $user, Position $scout): ?array
    {
        if ($user === null) {
            return null;
        }

        $coach = app(PortfolioRiskCoachService::class);
        $sector = $coach->normalizeSector($scout->sector_etf);

        if ($sector === null) {
            return null;
        }

        $direction = $scout->tradeDirection();
        $directionKey = $direction === TradeDirection::Short
            ? TradeDirection::Short->value
            : TradeDirection::Long->value;
        $directionLabel = $directionKey === TradeDirection::Short->value ? 'short' : 'long';

        $openCount = self::riskOnCounts($user)[$sector][$directionKey] ?? 0;
        $maxRiskOn = $coach->maxRiskOnPerSector();

        if ($openCount >= $maxRiskOn) {
            return [
                'state' => 'full',
                'icon' => self::ICON,
                'color' => 'warning',
                'tooltip' => sprintf(
                    'Sector %s %s vol — correlatierisico',
                    $sector,
                    $directionLabel,
                ),
            ];
        }

        if (! self::hasDirectionAwareMeewind($scout, $direction)) {
            return null;
        }

        return [
            'state' => 'interesting',
            'icon' => self::ICON,
            'color' => 'success',
            'tooltip' => sprintf(
                'Meewind · geen risk-on in %s %s',
                $sector,
                $directionLabel,
            ),
        ];
    }

    public static function icon(?User $user, Position $scout): ?string
    {
        return self::for($user, $scout)['icon'] ?? null;
    }

    public static function color(?User $user, Position $scout): string
    {
        return self::for($user, $scout)['color'] ?? 'gray';
    }

    public static function tooltip(?User $user, Position $scout): ?string
    {
        return self::for($user, $scout)['tooltip'] ?? null;
    }

    /**
     * @return array<string, array{long: int, short: int}>
     */
    private static function riskOnCounts(User $user): array
    {
        $userId = (int) $user->id;

        return self::$riskOnCountsByUser[$userId] ??= app(PortfolioRiskCoachService::class)
            ->openRiskOnSectorDirectionCounts($user);
    }

    private static function hasDirectionAwareMeewind(Position $scout, TradeDirection $direction): bool
    {
        if ($scout->sector_trend_positive === null) {
            return false;
        }

        if ($direction === TradeDirection::Short) {
            return $scout->sector_trend_positive === false;
        }

        return $scout->sector_trend_positive === true;
    }
}
