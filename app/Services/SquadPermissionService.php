<?php

namespace App\Services;

use App\Enums\SquadRole;
use App\Models\Squad;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SquadPermissionService
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'squad.manage',
        'user.invite',
        'role.assign',
        'position.manage',
        'position.activate',
        'scout.create',
        'scout.share',
        'scout.clone',
        'radar.view_squad',
        'api_credential.manage',
    ];

    public function seedPermissions(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function seedRolesForSquad(Squad $squad): void
    {
        $this->seedPermissions();

        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);

        $commander = Role::findOrCreate(SquadRole::Commander->value, 'web');
        $commander->syncPermissions(self::PERMISSIONS);

        $sniper = Role::findOrCreate(SquadRole::Sniper->value, 'web');
        $sniper->syncPermissions([
            'position.manage',
            'position.activate',
            'scout.create',
            'scout.share',
            'scout.clone',
            'radar.view_squad',
            'api_credential.manage',
        ]);

        $scout = Role::findOrCreate(SquadRole::Scout->value, 'web');
        $scout->syncPermissions([
            'scout.create',
            'radar.view_squad',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function assignRole(User $user, Squad $squad, SquadRole $role): void
    {
        $this->seedRolesForSquad($squad);

        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);

        $user->syncRoles([$role->value]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function setTeamContext(?int $squadId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($squadId);
    }
}
