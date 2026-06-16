<?php

namespace App\Policies;

use App\Models\Squad;
use App\Models\User;
use App\Services\SquadManagementService;

class SquadPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Squad $squad): bool
    {
        return app(SquadManagementService::class)->canManageMembers($squad, $user);
    }

    public function delete(User $user, Squad $squad): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return app(SquadManagementService::class)->canDelete($squad, $user);
    }
}
