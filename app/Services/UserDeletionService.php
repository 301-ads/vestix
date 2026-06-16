<?php

namespace App\Services;

use App\Models\Position;
use App\Models\Squad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserDeletionService
{
    public function __construct(private SquadManagementService $squads) {}

    public function canDelete(User $actor, User $target): bool
    {
        if (! $actor->isSuperAdmin()) {
            return false;
        }

        if ($actor->is($target)) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            return false;
        }

        return true;
    }

    public function delete(User $user): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $this->canDelete($actor, $user)) {
            throw new InvalidArgumentException('Je mag deze gebruiker niet verwijderen.');
        }

        DB::transaction(function () use ($user): void {
            Squad::query()
                ->where('owner_id', $user->id)
                ->get()
                ->each(fn (Squad $squad) => $this->squads->delete($squad));

            $user->squads()
                ->get()
                ->each(function (Squad $squad) use ($user): void {
                    if ($squad->users()->whereKey($user->id)->exists()) {
                        $this->squads->removeMember($squad, $user);
                    }
                });

            Position::query()->where('user_id', $user->id)->delete();

            $rolesTable = config('permission.table_names.model_has_roles');
            $permissionsTable = config('permission.table_names.model_has_permissions');

            DB::table($rolesTable)
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->delete();

            DB::table($permissionsTable)
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->delete();

            $user->delete();
        });
    }
}
