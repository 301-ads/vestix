<?php

namespace App\Services;

use App\Models\LeaderboardStat;
use App\Models\Position;
use App\Models\Squad;
use Illuminate\Support\Collection;

class PositionStatsAggregator
{
    public const MIN_TRADES_FOR_RANKING = 3;

    /**
     * @return array{
     *     closed_trades_count: int,
     *     win_rate: float,
     *     avg_roi_pct: float,
     *     freeride_count: int,
     *     qualifies_for_ranking: bool,
     * }
     */
    public function userClosedTradeStats(int $userId): array
    {
        $closed = Position::query()
            ->closed()
            ->forUser($userId)
            ->get();

        $count = $closed->count();

        if ($count === 0) {
            return [
                'closed_trades_count' => 0,
                'win_rate' => 0.0,
                'avg_roi_pct' => 0.0,
                'freeride_count' => 0,
                'qualifies_for_ranking' => false,
            ];
        }

        $wins = $closed->filter(fn (Position $p): bool => $p->unrealized_pnl > 0)->count();
        $winRate = ($wins / $count) * 100;
        $avgRoi = (float) $closed->avg(fn (Position $p): float => $p->unrealized_pnl_percentage);
        $freerideCount = $closed->filter(fn (Position $p): bool => $p->freeride_secured_at !== null)->count();

        return [
            'closed_trades_count' => $count,
            'win_rate' => round($winRate, 2),
            'avg_roi_pct' => round($avgRoi, 2),
            'freeride_count' => $freerideCount,
            'qualifies_for_ranking' => $count >= self::MIN_TRADES_FOR_RANKING,
        ];
    }

    public function rebuildForSquad(Squad $squad): void
    {
        $computedAt = now();
        $rankings = collect();

        foreach ($squad->users as $user) {
            $stats = $this->userClosedTradeStats($user->id);

            LeaderboardStat::query()->updateOrCreate(
                [
                    'squad_id' => $squad->id,
                    'user_id' => $user->id,
                ],
                [
                    'win_rate' => $stats['win_rate'],
                    'avg_roi_pct' => $stats['avg_roi_pct'],
                    'freeride_count' => $stats['freeride_count'],
                    'closed_trades_count' => $stats['closed_trades_count'],
                    'rank' => 0,
                    'computed_at' => $computedAt,
                ],
            );

            if ($stats['qualifies_for_ranking']) {
                $rankings->push([
                    'user_id' => $user->id,
                    'win_rate' => $stats['win_rate'],
                    'freeride_count' => $stats['freeride_count'],
                    'avg_roi_pct' => $stats['avg_roi_pct'],
                ]);
            }
        }

        $sorted = $rankings
            ->sortBy([
                ['win_rate', 'desc'],
                ['freeride_count', 'desc'],
                ['avg_roi_pct', 'desc'],
            ])
            ->values();

        foreach ($sorted as $index => $row) {
            LeaderboardStat::query()
                ->where('squad_id', $squad->id)
                ->where('user_id', $row['user_id'])
                ->update(['rank' => $index + 1]);
        }
    }

    public function rebuildAll(): void
    {
        Squad::query()->each(fn (Squad $squad) => $this->rebuildForSquad($squad));
    }

    /**
     * @return Collection<int, LeaderboardStat>
     */
    public function rankedStatsForSquad(int $squadId): Collection
    {
        return LeaderboardStat::query()
            ->with('user')
            ->where('squad_id', $squadId)
            ->where('closed_trades_count', '>=', self::MIN_TRADES_FOR_RANKING)
            ->orderBy('rank')
            ->get();
    }
}
