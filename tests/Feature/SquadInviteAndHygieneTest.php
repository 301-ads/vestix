<?php

namespace Tests\Feature;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Mail\SquadInviteMail;
use App\Models\Position;
use App\Models\SquadInvite;
use App\Models\User;
use App\Services\SquadManagementService;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SquadInviteAndHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_by_email_sends_magic_link_without_creating_user(): void
    {
        Mail::fake();
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();

        $result = app(SquadManagementService::class)->inviteByEmail(
            $squad,
            $commander,
            'newbie@vestix.test',
            SquadRole::Scout,
            'Newbie',
        );

        Mail::assertSent(SquadInviteMail::class);
        $this->assertDatabaseMissing('users', ['email' => 'newbie@vestix.test']);
        $this->assertDatabaseHas('squad_invites', [
            'squad_id' => $squad->id,
            'email' => 'newbie@vestix.test',
        ]);

        $this->get(route('squad-invites.show', ['token' => $result['plain_token']]))
            ->assertOk()
            ->assertSee('Account aanmaken');
    }

    public function test_accept_invite_creates_user_with_own_password(): void
    {
        Mail::fake();
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();

        $result = app(SquadManagementService::class)->inviteByEmail(
            $squad,
            $commander,
            'joiner@vestix.test',
            SquadRole::Sniper,
            'Joiner',
        );

        $this->post(route('squad-invites.accept', ['token' => $result['plain_token']]), [
            'name' => 'Joiner',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect('/admin');

        $user = User::query()->where('email', 'joiner@vestix.test')->firstOrFail();
        $this->assertTrue($squad->users()->whereKey($user->id)->exists());
        $this->assertNotNull(SquadInvite::query()->where('email', 'joiner@vestix.test')->value('accepted_at'));
    }

    public function test_leave_squad_ghosts_shared_scouts(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($sniper)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'LEAVE',
        ]);

        app(SquadManagementService::class)->leaveSquad($squad, $sniper);

        $this->assertFalse($squad->users()->whereKey($sniper->id)->exists());
        $shared->refresh();
        $this->assertSame(PositionVisibility::Private, $shared->visibility);
        $this->assertNull($shared->squad_id);
    }

    public function test_transfer_ownership_updates_owner_and_commander_role(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        app(SquadManagementService::class)->transferOwnership($squad, $commander, $sniper);

        $this->assertSame($sniper->id, $squad->fresh()->owner_id);
        app(SquadPermissionService::class)->setTeamContext($squad->id);
        $this->assertTrue($sniper->fresh()->hasRole(SquadRole::Commander->value));
        app(SquadPermissionService::class)->setTeamContext(null);
    }

    public function test_scout_role_can_share(): void
    {
        ['user' => $scout, 'squad' => $squad] = $this->createUserWithSquad(SquadRole::Scout);

        app(SquadPermissionService::class)->setTeamContext($squad->id);
        $this->assertTrue($scout->hasPermissionTo('scout.share'));
        $this->assertFalse($scout->hasPermissionTo('position.activate'));
        $this->assertFalse($scout->hasPermissionTo('scout.clone'));
        app(SquadPermissionService::class)->setTeamContext(null);
    }
}
