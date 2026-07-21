<?php

namespace Tests\Feature;

use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Filament\Pages\RegisterSquad;
use App\Mail\NewUserRegisteredMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => url('/admin/oauth/callback/google'),
            'vestix.admin_notification_emails' => ['admin@vestix.test'],
        ]);
    }

    public function test_google_oauth_redirect_route_is_available(): void
    {
        Socialite::fake('google');

        $this->get('/admin/oauth/google')
            ->assertRedirect();
    }

    public function test_new_google_user_is_registered_and_redirected_to_squad_creation(): void
    {
        Mail::fake();

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-1',
            'name' => 'Google Trader',
            'email' => 'google@vestix.test',
        ]));

        $this->get('/admin/oauth/callback/google?code=fake-code')
            ->assertRedirect(RegisterSquad::getUrl());

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'google@vestix.test')->first();

        $this->assertNotNull($user);
        $this->assertSame('Google Trader', $user->name);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);

        Mail::assertSent(NewUserRegisteredMail::class, function (NewUserRegisteredMail $mail) use ($user): bool {
            return $mail->user->is($user)
                && $mail->source === UserAccountCreatedSource::GoogleRegistration;
        });
    }

    public function test_existing_user_can_login_with_google_using_same_email(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@vestix.test',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-2',
            'name' => $user->name,
            'email' => 'existing@vestix.test',
        ]));

        $this->get('/admin/oauth/callback/google?code=fake-code')
            ->assertRedirect(RegisterSquad::getUrl())
            ->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->where('email', 'existing@vestix.test')->count());
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_google_registration_dispatches_user_account_created_event(): void
    {
        Event::fake([UserAccountCreated::class]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-3',
            'name' => 'Event User',
            'email' => 'event@vestix.test',
        ]));

        $this->get('/admin/oauth/callback/google?code=fake-code');

        Event::assertDispatched(UserAccountCreated::class, function (UserAccountCreated $event): bool {
            return $event->user->email === 'event@vestix.test'
                && $event->source === UserAccountCreatedSource::GoogleRegistration;
        });
    }
}
