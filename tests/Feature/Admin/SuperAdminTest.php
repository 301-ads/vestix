<?php

namespace Tests\Feature\Admin;

use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Filament\Auth\Pages\Login;
use App\Filament\Pages\ManageSquadSettings;
use App\Filament\Pages\RegisterSquad;
use App\Filament\Resources\Admin\SquadResource;
use App\Filament\Resources\Admin\UserResource;
use App\Filament\Resources\Admin\SquadResource\Pages\ListSquads;
use App\Filament\Resources\Admin\UserResource\Pages\ListUsers;
use App\Mail\NewUserRegisteredMail;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadManagementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_sees_beheer_navigation_resources(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);

        $this->assertTrue(SquadResource::shouldRegisterNavigation());
        $this->assertTrue(UserResource::shouldRegisterNavigation());
        $this->assertTrue(SquadResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
    }

    public function test_regular_user_cannot_access_admin_resources(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->assertFalse(SquadResource::shouldRegisterNavigation());
        $this->assertFalse(UserResource::shouldRegisterNavigation());
        $this->assertFalse(SquadResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());
    }

    public function test_super_admin_can_list_all_squads_and_users(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ['squad' => $squad] = $this->createUserWithSquad();

        $this->actingAs($admin);

        Livewire::test(ListSquads::class)
            ->assertCanSeeTableRecords([$squad]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords(User::query()->whereKeyNot($admin->id)->get());
    }

    public function test_super_admin_without_squad_skips_onboarding_after_login(): void
    {
        $admin = User::factory()->superAdmin()->create();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $admin->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($admin);
    }

    public function test_super_admin_can_view_squad_settings_read_only(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ['squad' => $squad] = $this->createUserWithSquad();

        $this->actingAs($admin);

        Livewire::test(ManageSquadSettings::class, ['squadId' => $squad->id])
            ->assertSet('activeSquad.id', $squad->id)
            ->assertSee($squad->name);
    }

    public function test_super_admin_create_user_command(): void
    {
        $this->artisan('vestix:create-user', [
            'email' => 'admin@vestix.test',
            '--name' => 'Admin',
            '--password' => 'secret123',
            '--super-admin' => true,
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@vestix.test')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertFalse($user->squads()->exists());
    }
}
