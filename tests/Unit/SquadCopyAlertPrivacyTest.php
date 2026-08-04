<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertChannelType;
use App\Enums\AlertEventType;
use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\SquadCopyAlertService;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SquadCopyAlertPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ghost_mode_open_does_not_notify_squad(): void
    {
        ['user' => $actor, 'squad' => $squad] = $this->createUserWithSquad();
        $mate = User::factory()->create(['telegram_chat_id' => '999']);
        $squad->users()->attach($mate);
        app(SquadPermissionService::class)->assignRole($mate, $squad, SquadRole::Sniper);

        UserAlertPreference::ensureDefaultsForUser($mate);
        $mate->alertPreferences()
            ->where('channel_type', AlertChannelType::Telegram)
            ->update([
                'is_active' => true,
                'active_events' => [AlertEventType::SquadCopyAlert->value],
            ]);

        $dispatcher = Mockery::mock(AlertDispatcher::class);
        $dispatcher->shouldNotReceive('dispatchNow');
        $this->app->instance(AlertDispatcher::class, $dispatcher);

        $position = Position::factory()->for($actor)->create([
            'status' => 'open',
            'visibility' => PositionVisibility::Private,
            'squad_id' => null,
            'ticker' => 'GHOST',
        ]);

        app(SquadCopyAlertService::class)->notifySquadMembers($position, 'een nieuwe positie geopend op');
    }

    public function test_shared_open_notifies_only_that_squad(): void
    {
        ['user' => $actor, 'squad' => $squad] = $this->createUserWithSquad();
        $mate = User::factory()->create(['telegram_chat_id' => '111']);
        $squad->users()->attach($mate);
        app(SquadPermissionService::class)->assignRole($mate, $squad, SquadRole::Sniper);

        $outsider = User::factory()->create(['telegram_chat_id' => '222']);
        ['squad' => $otherSquad] = $this->createUserWithSquad();
        $otherSquad->users()->attach([$outsider->id, $actor->id]);
        app(SquadPermissionService::class)->assignRole($outsider, $otherSquad, SquadRole::Sniper);

        foreach ([$mate, $outsider] as $user) {
            UserAlertPreference::ensureDefaultsForUser($user);
            $user->alertPreferences()
                ->where('channel_type', AlertChannelType::Telegram)
                ->update([
                    'is_active' => true,
                    'active_events' => [AlertEventType::SquadCopyAlert->value],
                ]);
        }

        $dispatcher = Mockery::mock(AlertDispatcher::class);
        $dispatcher->shouldReceive('dispatchNow')
            ->once()
            ->withArgs(function (int $userId, int $positionId, AlertEventType $event) use ($mate): bool {
                return $userId === $mate->id
                    && $event === AlertEventType::SquadCopyAlert;
            });
        $this->app->instance(AlertDispatcher::class, $dispatcher);

        $position = Position::factory()->for($actor)->create([
            'status' => 'open',
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'SHARE',
        ]);

        app(SquadCopyAlertService::class)->notifySquadMembers($position, 'een nieuwe positie geopend op');
    }
}
