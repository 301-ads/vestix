<?php

namespace Tests\Feature;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\User;
use App\Services\SquadManagementService;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    public function test_add_member_rejects_unknown_email(): void
    {
        ['squad' => $squad] = $this->createUserWithSquad();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Geen bestaand account voor dit e-mailadres. Stuur een uitnodigingslink.');

        app(SquadManagementService::class)->addMember($squad, 'new@vestix.test', SquadRole::Sniper);
    }

    public function test_add_member_rejects_duplicate_membership(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $management = app(SquadManagementService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deze gebruiker is al lid van deze squad.');

        $management->addMember($squad, $commander->email, SquadRole::Sniper);
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

        $this->expectException(InvalidArgumentException::class);

        $management->removeMember($squad, $commander);
    }

    public function test_invite_by_email_requires_name_for_new_user(): void
    {
        Mail::fake();
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Naam is verplicht voor een nieuwe uitnodiging.');

        app(SquadManagementService::class)->inviteByEmail(
            $squad,
            $commander,
            'noname@vestix.test',
            SquadRole::Scout,
        );
    }
}
