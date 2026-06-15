<?php

namespace Tests\Feature;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\User;
use App\Services\SquadManagementService;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SquadManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_member_links_existing_user_without_password(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $existing = User::factory()->create(['email' => 'existing@vestix.test']);

        $member = app(SquadManagementService::class)->addMember(
            $squad,
            'existing@vestix.test',
            SquadRole::Sniper,
        );

        $this->assertTrue($member->is($existing));
        $this->assertTrue($squad->users()->whereKey($existing->id)->exists());

        app(SquadPermissionService::class)->setTeamContext($squad->id);
        $this->assertTrue($existing->fresh()->hasRole(SquadRole::Sniper->value));
        app(SquadPermissionService::class)->setTeamContext(null);
    }

    public function test_add_member_creates_new_user_with_name_and_password(): void
    {
        ['squad' => $squad] = $this->createUserWithSquad();

        $member = app(SquadManagementService::class)->addMember(
            $squad,
            'new@vestix.test',
            SquadRole::Scout,
            'New Trader',
            'secret123',
        );

        $this->assertSame('new@vestix.test', $member->email);
        $this->assertSame('New Trader', $member->name);
        $this->assertTrue($squad->users()->whereKey($member->id)->exists());
    }

    public function test_add_member_rejects_duplicate_membership(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $management = app(SquadManagementService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deze gebruiker is al lid van deze squad.');

        $management->addMember($squad, $commander->email, SquadRole::Sniper);
    }

    public function test_add_member_requires_name_and_password_for_new_user(): void
    {
        ['squad' => $squad] = $this->createUserWithSquad();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Naam en wachtwoord zijn verplicht voor een nieuw account.');

        app(SquadManagementService::class)->addMember($squad, 'new@vestix.test', SquadRole::Sniper);
    }

    public function test_commander_can_delete_owned_squad_and_shared_scouts_become_private(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();

        $sharedScout = Position::factory()->for($commander)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
        ]);

        app(SquadManagementService::class)->delete($squad);

        $this->assertDatabaseMissing('squads', ['id' => $squad->id]);
        $this->assertDatabaseMissing('squad_user', ['squad_id' => $squad->id]);

        $sharedScout->refresh();
        $this->assertSame(PositionVisibility::Private, $sharedScout->visibility);
        $this->assertNull($sharedScout->squad_id);
    }

    public function test_commander_can_remove_member_and_change_role(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $management = app(SquadManagementService::class);

        $management->changeMemberRole($squad, $sniper, SquadRole::Scout);

        app(SquadPermissionService::class)->setTeamContext($squad->id);
        $this->assertTrue($sniper->fresh()->hasRole(SquadRole::Scout->value));
        app(SquadPermissionService::class)->setTeamContext(null);

        $management->removeMember($squad, $sniper);

        $this->assertFalse($squad->users()->whereKey($sniper->id)->exists());
    }

    public function test_cannot_remove_last_commander_or_squad_owner(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $management = app(SquadManagementService::class);

        $this->expectException(\InvalidArgumentException::class);
        $management->removeMember($squad, $commander);
    }

    public function test_cannot_demote_last_commander(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();

        $this->expectException(\InvalidArgumentException::class);

        app(SquadManagementService::class)->changeMemberRole($squad, $commander, SquadRole::Sniper);
    }

    public function test_non_owner_cannot_delete_squad(): void
    {
        ['user' => $owner, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $this->assertFalse(app(SquadManagementService::class)->canDelete($squad, $sniper));
    }
}
