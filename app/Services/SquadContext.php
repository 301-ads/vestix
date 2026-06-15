<?php

namespace App\Services;

use App\Enums\SquadRole;
use App\Models\Squad;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

class SquadContext
{
    public function userCanInSquad(User $user, Squad $squad, string $permission): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($squad->id);

        $allowed = $user->can($permission);

        $registrar->setPermissionsTeamId($previousTeamId);

        return $allowed;
    }

    public function userCanInAnySquad(User $user, string $permission): bool
    {
        foreach ($user->squads as $squad) {
            if ($this->userCanInSquad($user, $squad, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Squad>
     */
    public function squadsWhereUserCan(User $user, string $permission): Collection
    {
        return $user->squads->filter(
            fn (Squad $squad): bool => $this->userCanInSquad($user, $squad, $permission),
        )->values();
    }

    public function isCommanderOf(User $user, Squad $squad): bool
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($squad->id);

        $isCommander = $user->hasRole(SquadRole::Commander->value);

        $registrar->setPermissionsTeamId($previousTeamId);

        return $isCommander;
    }

    public function resolveSquadForUser(User $user, ?int $squadId): ?Squad
    {
        if ($squadId !== null) {
            $squad = $user->squads()->whereKey($squadId)->first();

            return $squad instanceof Squad ? $squad : null;
        }

        return $user->squads()->orderBy('squads.id')->first();
    }
}
