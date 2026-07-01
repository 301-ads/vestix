<?php

namespace App\Support;

use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;

class OpenPositionsFilters
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'at_risk' => 'Met open risico',
            'secured_profit' => 'Veiliggestelde winst',
            'winners' => 'Winnaars',
            'losers' => 'Verliezers',
            'danger_zone' => 'In gevarenzone (< 2%)',
        ];
    }

    public static function indicatorLabel(?string $focus): ?string
    {
        if (blank($focus)) {
            return null;
        }

        return self::options()[$focus] ?? null;
    }

    public static function matches(Position $position, string $focus): bool
    {
        return match ($focus) {
            'at_risk' => $position->status === 'open' && $position->capital_risk_dollars > 0,
            'secured_profit' => $position->status === 'open' && $position->locked_in_profit_dollars > 0,
            'winners' => $position->status === 'open' && $position->unrealized_pnl >= 0,
            'losers' => $position->status === 'open' && $position->unrealized_pnl < 0,
            'danger_zone' => $position->isInDangerZone(),
            default => false,
        };
    }

    /**
     * @param  Builder<Position>  $query
     * @return Builder<Position>
     */
    public static function apply(Builder $query, ?string $focus): Builder
    {
        if (blank($focus)) {
            return $query;
        }

        $ids = (clone $query)->pluck('id');

        if ($ids->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $matchingIds = Position::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Position $position): bool => self::matches($position, $focus))
            ->pluck('id');

        if ($matchingIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $matchingIds);
    }
}
