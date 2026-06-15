<?php

namespace App\Services;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\Squad;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

class SquadManagementService
{
    public function __construct(private SquadPermissionService $permissions) {}

    public function delete(Squad $squad): void
    {
        Position::query()
            ->where('squad_id', $squad->id)
            ->update([
                'squad_id' => null,
                'visibility' => PositionVisibility::Private->value,
            ]);

        $teamKey = config('permission.column_names.team_foreign_key');
        $rolesTable = config('permission.table_names.model_has_roles');

        DB::table($rolesTable)->where($teamKey, $squad->id)->delete();

        $squad->users()->detach();
        $squad->delete();
    }

    public function removeMember(Squad $squad, User $member): void
    {
        if (! $squad->users()->whereKey($member->id)->exists()) {
            throw new InvalidArgumentException('Gebruiker is geen lid van deze squad.');
        }

        if ($squad->owner_id === $member->id) {
            throw new InvalidArgumentException('De squad-eigenaar kan niet worden verwijderd. Draag eerst het eigenaarschap over of verwijder de squad.');
        }

        if ($this->commanderCount($squad) === 1 && $this->hasRole($squad, $member, SquadRole::Commander)) {
            throw new InvalidArgumentException('De laatste Commander kan niet worden verwijderd.');
        }

        $this->revokeRoles($squad, $member);
        $squad->users()->detach($member->id);
    }

    public function addMember(
        Squad $squad,
        string $email,
        SquadRole $role,
        ?string $name = null,
        ?string $password = null,
    ): User {
        $email = strtolower(trim($email));

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            if ($squad->users()->whereKey($user->id)->exists()) {
                throw new InvalidArgumentException('Deze gebruiker is al lid van deze squad.');
            }

            $squad->users()->attach($user->id);
            $this->permissions->assignRole($user, $squad, $role);

            return $user;
        }

        if (blank($name) || blank($password)) {
            throw new InvalidArgumentException('Naam en wachtwoord zijn verplicht voor een nieuw account.');
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $squad->users()->attach($user->id);
        $this->permissions->assignRole($user, $squad, $role);

        return $user;
    }

    public function changeMemberRole(Squad $squad, User $member, SquadRole $role): void
    {
        if (! $squad->users()->whereKey($member->id)->exists()) {
            throw new InvalidArgumentException('Gebruiker is geen lid van deze squad.');
        }

        if (
            $this->hasRole($squad, $member, SquadRole::Commander)
            && $role !== SquadRole::Commander
            && $this->commanderCount($squad) === 1
        ) {
            throw new InvalidArgumentException('Er moet minstens één Commander in de squad blijven.');
        }

        $this->permissions->assignRole($member, $squad, $role);
    }

    public function canDelete(Squad $squad, User $user): bool
    {
        return app(SquadContext::class)->userCanInSquad($user, $squad, 'squad.manage')
            && $squad->owner_id === $user->id
            && $user->squads()->whereKey($squad)->exists();
    }

    public function canManageMembers(Squad $squad, User $user): bool
    {
        return app(SquadContext::class)->userCanInSquad($user, $squad, 'squad.manage')
            && $user->squads()->whereKey($squad)->exists();
    }

    public function canRemoveMember(Squad $squad, User $actor, User $member): bool
    {
        if (! $this->canManageMembers($squad, $actor)) {
            return false;
        }

        if ($squad->owner_id === $member->id) {
            return false;
        }

        if ($actor->is($member) && $this->commanderCount($squad) === 1 && $this->hasRole($squad, $member, SquadRole::Commander)) {
            return false;
        }

        return true;
    }

    public function commanderCount(Squad $squad): int
    {
        return $squad->users()
            ->get()
            ->filter(fn (User $user): bool => $this->hasRole($squad, $user, SquadRole::Commander))
            ->count();
    }

    private function hasRole(Squad $squad, User $user, SquadRole $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);
        $hasRole = $user->hasRole($role->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }

    private function revokeRoles(Squad $squad, User $user): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);
        $user->syncRoles([]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }
}
