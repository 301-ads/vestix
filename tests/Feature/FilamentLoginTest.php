<?php

namespace Tests\Feature;

use App\Filament\Pages\RegisterSquad;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_squad_can_login(): void
    {
        ['user' => $user] = $this->createUserWithSquad();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_without_squad_redirects_to_squad_creation_after_login(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(RegisterSquad::getUrl());

        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_redirects_to_squad_creation(): void
    {
        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'New Trader',
                'email' => 'new@vestix.test',
                'password' => 'password',
                'passwordConfirmation' => 'password',
            ])
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertRedirect(RegisterSquad::getUrl());

        $this->assertDatabaseHas('users', ['email' => 'new@vestix.test']);
    }

    public function test_password_reset_request_page_is_available(): void
    {
        $this->get('/admin/password-reset/request')
            ->assertOk();
    }

    public function test_welcome_page_is_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Vestix')
            ->assertSee('Start een Squad');
    }

    public function test_create_user_command_provisions_commander_access(): void
    {
        $this->artisan('vestix:create-user', [
            'email' => 'trader@vestix.test',
            '--name' => 'Trader',
            '--password' => 'secret123',
            '--squad' => 'Alpha Squad',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'trader@vestix.test')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->squads()->where('slug', 'alpha-squad')->exists());
    }
}
