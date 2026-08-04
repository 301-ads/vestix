<?php

namespace App\Services;

use App\Enums\LeaderboardTrack;
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
            ->nonLegacy()
            ->forUser($userId)
            ->get();

        return $this->statsFromClosedPositions($closed);
    }

    /**
     * Closed clones of this user's spots, taken by members of the given squad.
     *
     * @return array{
     *     closed_trades_count: int,
     *     win_rate: float,
     *     avg_roi_pct: float,
     *     freeride_count: int,
     *     qualifies_for_ranking: bool,
     * }
     */
    public function userAnalystStats(int $spotterUserId, Squad $squad): array
    {
        $memberIds = $squad->users()->pluck('users.id');

        if ($memberIds->isEmpty()) {
            return $this->emptyStats();
        }

        $closedClones = Position::query()
            ->closed()
            ->nonLegacy()
            ->whereNotNull('cloned_from_id')
            ->whereIn('user_id', $memberIds)
            ->whereHas('clonedFrom', fn ($query) => $query->where('user_id', $spotterUserId))
            ->get();

        return $this->statsFromClosedPositions($closedClones);
    }

    public function rebuildForSquad(Squad $squad): void
    {
        $computedAt = now();

        foreach (LeaderboardTrack::cases() as $track) {
            $this->rebuildTrackForSquad($squad, $track, $computedAt);
        }
    }

    public function rebuildAll(): void
    {
        Squad::query()->each(fn (Squad $squad) => $this->rebuildForSquad($squad));
    }

    /**
     * @return Collection<int, LeaderboardStat>
     */
    public function rankedStatsForSquad(int $squadId, LeaderboardTrack $track = LeaderboardTrack::Executor): Collection
    {
        return LeaderboardStat::query()
            ->with('user')
            ->where('squad_id', $squadId)
            ->where('track', $track)
            ->where('closed_trades_count', '>=', self::MIN_TRADES_FOR_RANKING)
            ->orderBy('rank')
            ->get();
    }

    /**
     * @param  Collection<int, Position>  $closed
     * @return array{
     *     closed_trades_count: int,
     *     win_rate: float,
     *     avg_roi_pct: float,
     *     freeride_count: int,
     *     qualifies_for_ranking: bool,
     * }
     */
    private function statsFromClosedPositions(Collection $closed): array
    {
        $count = $closed->count();

        if ($count === 0) {
            return $this->emptyStats();
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

    /**
     * @return array{
     *     closed_trades_count: int,
     *     win_rate: float,
     *     avg_roi_pct: float,
     *     freeride_count: int,
     *     qualifies_for_ranking: bool,
     * }
     */
    private function emptyStats(): array
    {
        return [
            'closed_trades_count' => 0,
            'win_rate' => 0.0,
            'avg_roi_pct' => 0.0,
            'freeride_count' => 0,
            'qualifies_for_ranking' => false,
        ];
    }

    private function rebuildTrackForSquad(Squad $squad, LeaderboardTrack $track, mixed $computedAt): void
    {
        $rankings = collect();

        foreach ($squad->users as $user) {
            $stats = match ($track) {
                LeaderboardTrack::Executor => $this->userClosedTradeStats($user->id),
                LeaderboardTrack::Analyst => $this->userAnalystStats($user->id, $squad),
            };

            LeaderboardStat::query()->updateOrCreate(
                [
                    'squad_id' => $squad->id,
                    'user_id' => $user->id,
                    'track' => $track->value,
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
                ->where('track', $track->value)
                ->update(['rank' => $index + 1]);
        }
    }
}
