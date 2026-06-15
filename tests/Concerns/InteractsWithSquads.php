<?php

namespace Tests\Concerns;

use App\Enums\SquadRole;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadPermissionService;
use Filament\Facades\Filament;

trait InteractsWithSquads
{
    /**
     * @return array{user: User, squad: Squad}
     */
    protected function createUserWithSquad(SquadRole $role = SquadRole::Commander): array
    {
        $user = User::factory()->create();
        $squad = Squad::factory()->create(['owner_id' => $user->id]);
        $squad->users()->attach($user->id);

        app(SquadPermissionService::class)->assignRole($user, $squad, $role);

        return compact('user', 'squad');
    }

    protected function actingAsFilamentUser(User $user, ?Squad $squad = null): static
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($user);

        return $this;
    }

    protected function authenticateFilament(SquadRole $role = SquadRole::Commander): User
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad($role);

        $this->actingAsFilamentUser($user, $squad);

        return $user;
    }
}
