<?php

namespace Tests\Feature\Admin;

use App\Enums\SquadRole;
use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Mail\NewUserRegisteredMail;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadManagementService;
use Filament\Auth\Pages\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class NewUserAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'vestix.admin_notification_emails' => ['admin@vestix.test'],
        ]);
    }

    public function test_sends_mail_when_user_account_created_event_is_dispatched(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        UserAccountCreated::dispatch($user, UserAccountCreatedSource::Artisan);

        Mail::assertSent(NewUserRegisteredMail::class, function (NewUserRegisteredMail $mail) use ($user): bool {
            return $mail->hasTo('admin@vestix.test')
                && $mail->user->is($user)
                && $mail->source === UserAccountCreatedSource::Artisan;
        });
    }

    public function test_sends_mail_after_public_registration(): void
    {
        Mail::fake();

        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'New Trader',
                'email' => 'new@vestix.test',
                'password' => 'password',
                'passwordConfirmation' => 'password',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        Mail::assertSent(NewUserRegisteredMail::class, function (NewUserRegisteredMail $mail): bool {
            return $mail->hasTo('admin@vestix.test')
                && $mail->user->email === 'new@vestix.test'
                && $mail->source === UserAccountCreatedSource::Registration;
        });
    }

    public function test_sends_mail_when_squad_invite_creates_account(): void
    {
        Mail::fake();

        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad(SquadRole::Commander);

        app(SquadManagementService::class)->addMember(
            $squad,
            'invited@vestix.test',
            SquadRole::Sniper,
            'Invited User',
            'password123',
        );

        Mail::assertSent(NewUserRegisteredMail::class, function (NewUserRegisteredMail $mail): bool {
            return $mail->hasTo('admin@vestix.test')
                && $mail->user->email === 'invited@vestix.test'
                && $mail->source === UserAccountCreatedSource::SquadInvite;
        });
    }

    public function test_sends_mail_when_artisan_creates_user(): void
    {
        Mail::fake();

        $this->artisan('vestix:create-user', [
            'email' => 'cli@vestix.test',
            '--name' => 'CLI User',
            '--password' => 'secret123',
        ])->assertSuccessful();

        Mail::assertSent(NewUserRegisteredMail::class, function (NewUserRegisteredMail $mail): bool {
            return $mail->hasTo('admin@vestix.test')
                && $mail->user->email === 'cli@vestix.test'
                && $mail->source === UserAccountCreatedSource::Artisan;
        });
    }

    public function test_does_not_send_mail_when_admin_emails_not_configured(): void
    {
        Mail::fake();
        config(['vestix.admin_notification_emails' => []]);

        $user = User::factory()->create();

        UserAccountCreated::dispatch($user, UserAccountCreatedSource::Artisan);

        Mail::assertNothingSent();
    }

    public function test_does_not_send_mail_when_linking_existing_user_to_squad(): void
    {
        Mail::fake();

        ['squad' => $squad] = $this->createUserWithSquad(SquadRole::Commander);
        $existing = User::factory()->create(['email' => 'existing@vestix.test']);

        app(SquadManagementService::class)->addMember(
            $squad,
            $existing->email,
            SquadRole::Scout,
        );

        Mail::assertNothingSent();
    }
}
