<?php

namespace App\Services;

use App\Enums\PositionVisibility;
use App\Enums\SquadActivityType;
use App\Models\Position;
use App\Models\SquadActivity;
use App\Models\User;

class SquadActivityRecorder
{
    public function recordShare(Position $position): void
    {
        if ($position->visibility !== PositionVisibility::Squad || $position->squad_id === null) {
            return;
        }

        $this->write(
            squadId: (int) $position->squad_id,
            actorUserId: (int) $position->user_id,
            positionId: $position->id,
            type: SquadActivityType::Shared,
            ticker: (string) $position->ticker,
            meta: [
                'grade' => $position->entry_setup_grade ?? $position->buy_stop_review_setup_grade,
            ],
        );
    }

    public function recordClone(Position $source, User $cloner): void
    {
        if ($source->squad_id === null) {
            return;
        }

        $this->write(
            squadId: (int) $source->squad_id,
            actorUserId: $cloner->id,
            positionId: $source->id,
            type: SquadActivityType::Cloned,
            ticker: (string) $source->ticker,
            meta: [
                'spotter_user_id' => $source->user_id,
            ],
        );
    }

    public function recordOpened(Position $position): void
    {
        if (! $this->isAnnouncable($position)) {
            return;
        }

        $this->write(
            squadId: (int) $position->squad_id,
            actorUserId: (int) $position->user_id,
            positionId: $position->id,
            type: SquadActivityType::Opened,
            ticker: (string) $position->ticker,
        );
    }

    public function recordClosed(Position $position): void
    {
        if (! $this->isAnnouncable($position)) {
            return;
        }

        $this->write(
            squadId: (int) $position->squad_id,
            actorUserId: (int) $position->user_id,
            positionId: $position->id,
            type: SquadActivityType::Closed,
            ticker: (string) $position->ticker,
            meta: [
                'roi_pct' => round((float) $position->unrealized_pnl_percentage, 2),
                'freeride' => $position->freeride_secured_at !== null,
            ],
        );
    }

    private function isAnnouncable(Position $position): bool
    {
        return $position->visibility === PositionVisibility::Squad
            && $position->squad_id !== null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function write(
        int $squadId,
        int $actorUserId,
        ?int $positionId,
        SquadActivityType $type,
        string $ticker,
        array $meta = [],
    ): void {
        SquadActivity::query()->create([
            'squad_id' => $squadId,
            'actor_user_id' => $actorUserId,
            'position_id' => $positionId,
            'type' => $type,
            'ticker' => strtoupper($ticker),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
