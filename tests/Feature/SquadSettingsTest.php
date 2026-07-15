<?php

namespace Tests\Feature;

use App\Enums\SquadRole;
use App\Filament\Pages\ManageSquadSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SquadSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_squad_member_can_view_settings_tab(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad(SquadRole::Scout);
        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(ManageSquadSettings::class)
            ->assertOk()
            ->assertSee('Instellingen')
            ->assertSee('Jouw squad')
            ->assertSee($squad->name)
            ->assertSee('Scout');
    }

    public function test_commander_sees_members_tab_and_can_manage(): void
    {
        $user = $this->authenticateFilament();

        Livewire::test(ManageSquadSettings::class)
            ->assertOk()
            ->assertSee('Squad leden beheren')
            ->assertSet('activeTab', 'settings')
            ->set('activeTab', 'members')
            ->assertSee('Lid toevoegen');
    }

    public function test_scout_does_not_see_members_tab(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad(SquadRole::Scout);
        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(ManageSquadSettings::class)
            ->assertOk()
            ->assertDontSee('Squad leden beheren')
            ->assertDontSee('Lid toevoegen');
    }

    public function test_commander_can_add_existing_user_via_ui(): void
    {
        $user = $this->authenticateFilament();
        $existing = User::factory()->create(['email' => 'existing@vestix.test']);

        Livewire::test(ManageSquadSettings::class)
            ->set('activeTab', 'members')
            ->callTableAction('add_member', data: [
                'invite_method' => 'email',
                'email' => $existing->email,
                'role' => SquadRole::Sniper->value,
            ])
            ->assertNotified('Lid gekoppeld');

        $this->assertTrue($user->squads()->first()->users()->whereKey($existing->id)->exists());
    }

    public function test_commander_can_create_new_user_via_ui(): void
    {
        $user = $this->authenticateFilament();

        Livewire::test(ManageSquadSettings::class)
            ->set('activeTab', 'members')
            ->callTableAction('add_member', data: [
                'invite_method' => 'email',
                'email' => 'newmember@vestix.test',
                'name' => 'New Member',
                'password' => 'secret123',
                'role' => SquadRole::Scout->value,
            ])
            ->assertNotified('Account aangemaakt en toegevoegd');

        $this->assertDatabaseHas('users', ['email' => 'newmember@vestix.test']);
        $this->assertTrue($user->squads()->first()->users()->where('email', 'newmember@vestix.test')->exists());
    }

    public function test_member_without_role_sees_assignment_warning(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $member = User::factory()->create();
        $squad->users()->attach($member->id);

        $this->actingAsFilamentUser($member, $squad);

        Livewire::test(ManageSquadSettings::class)
            ->assertOk()
            ->assertSee('Rol toewijzing');
    }
}
