<?php

namespace App\Policies;

use App\Enums\PositionVisibility;
use App\Models\Position;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadContext;

class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Position $position): bool
    {
        return $position->isOwnedBy($user) || $this->viewSquadScout($user, $position);
    }

    public function create(User $user): bool
    {
        $context = app(SquadContext::class);

        return $context->userCanInAnySquad($user, 'position.manage')
            || $context->userCanInAnySquad($user, 'scout.create');
    }

    public function update(User $user, Position $position): bool
    {
        if ($position->isOwnedBy($user)) {
            return true;
        }

        return $this->canManageSquadScout($user, $position);
    }

    public function delete(User $user, Position $position): bool
    {
        if ($position->isOwnedBy($user)) {
            return true;
        }

        return $this->canManageSquadScout($user, $position);
    }

    public function activate(User $user, Position $position): bool
    {
        if (! $position->isOwnedBy($user)) {
            return false;
        }

        return app(SquadContext::class)->userCanInAnySquad($user, 'position.activate');
    }

    public function clone(User $user, Position $position): bool
    {
        if ($position->visibility !== PositionVisibility::Squad) {
            return false;
        }

        if ($position->isOwnedBy($user)) {
            return false;
        }

        if ($position->squad_id === null) {
            return false;
        }

        $squad = Squad::query()->find($position->squad_id);

        if (! $squad instanceof Squad) {
            return false;
        }

        return app(SquadContext::class)->userCanInSquad($user, $squad, 'scout.clone')
            && $this->viewSquadScout($user, $position);
    }

    public function viewSquadScout(User $user, Position $position): bool
    {
        if ($position->visibility !== PositionVisibility::Squad || $position->status !== 'scout') {
            return false;
        }

        if ($position->squad_id === null) {
            return false;
        }

        $squad = Squad::query()->find($position->squad_id);

        if (! $squad instanceof Squad) {
            return false;
        }

        return $user->squads()->whereKey($squad->id)->exists()
            && app(SquadContext::class)->userCanInSquad($user, $squad, 'radar.view_squad');
    }

    private function canManageSquadScout(User $user, Position $position): bool
    {
        if ($position->status !== 'scout' || $position->visibility !== PositionVisibility::Squad) {
            return false;
        }

        if ($position->squad_id === null) {
            return false;
        }

        $squad = Squad::query()->find($position->squad_id);

        if (! $squad instanceof Squad) {
            return false;
        }

        return app(SquadContext::class)->isCommanderOf($user, $squad);
    }
}
