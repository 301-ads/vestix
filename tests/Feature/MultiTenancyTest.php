<?php

namespace Tests\Feature;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Events\SquadRadarTargetPosted;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Models\Position;
use App\Models\User;
use App\Services\SquadContext;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_squad_permissions_resolve_in_squad_context(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAs($user);

        $this->assertTrue(
            app(SquadContext::class)->userCanInSquad($user, $squad, 'scout.create')
        );
    }

    public function test_commander_sees_create_scout_action_on_setup_radar(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(ListScouts::class)
            ->assertActionVisible('createScout');
    }

    public function test_new_scouts_default_to_private_visibility(): void
    {
        $user = $this->authenticateFilament();
        $scout = Position::factory()->for($user)->scout()->create();

        $this->assertSame(PositionVisibility::Private, $scout->visibility);
    }

    public function test_user_only_sees_own_positions_in_portfolio_query(): void
    {
        ['user' => $userA, 'squad' => $squad] = $this->createUserWithSquad();
        $userB = User::factory()->create();
        $squad->users()->attach($userB);
        app(SquadPermissionService::class)->assignRole($userB, $squad, SquadRole::Sniper);

        $own = Position::factory()->for($userA)->create(['ticker' => 'OWN', 'status' => 'open']);
        Position::factory()->for($userB)->create(['ticker' => 'OTHER', 'status' => 'open']);

        $this->assertTrue(Position::open()->forUser($userA->id)->whereKey($own->id)->exists());
        $this->assertFalse(Position::open()->forUser($userA->id)->where('ticker', 'OTHER')->exists());
    }

    public function test_squad_shared_scout_visible_to_teammate_not_outsider(): void
    {
        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $teammate = User::factory()->create();
        $outsider = User::factory()->create();
        $squad->users()->attach($teammate);
        app(SquadPermissionService::class)->assignRole($teammate, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'ASML',
        ]);

        $this->assertTrue(
            Position::squadShared($squad->id)->whereKey($shared->id)->exists()
        );

        $this->actingAs($teammate);
        app(SquadPermissionService::class)->setTeamContext($squad->id);
        $this->assertTrue($teammate->can('view', $shared));

        $this->actingAs($outsider);
        $this->assertFalse($outsider->can('view', $shared));
    }

    public function test_clone_target_copies_setup_to_private_scout_for_sniper(): void
    {
        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'NVDA',
            'entry_price' => 120.50,
            'signal_high' => 119.00,
            'latest_atr_14' => 2.50,
        ]);

        $this->actingAs($sniper);
        app(SquadPermissionService::class)->setTeamContext($squad->id);

        $clone = $shared->cloneForUser($sniper);

        $this->assertSame($sniper->id, $clone->user_id);
        $this->assertSame(PositionVisibility::Private, $clone->visibility);
        $this->assertNull($clone->squad_id);
        $this->assertSame($shared->id, $clone->cloned_from_id);
        $this->assertSame('NVDA', $clone->ticker);
        $this->assertEquals(120.50, (float) $clone->entry_price);
        $this->assertSame('scout', $clone->status);
    }

    public function test_scout_role_cannot_activate_positions(): void
    {
        ['user' => $scout, 'squad' => $squad] = $this->createUserWithSquad(SquadRole::Scout);
        $position = Position::factory()->for($scout)->scout()->create();

        $this->actingAs($scout);
        app(SquadPermissionService::class)->setTeamContext($squad->id);

        $this->assertFalse($scout->can('activate', $position));
    }

    public function test_squad_radar_post_dispatches_telegram_to_teammates(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['vestix.telegram.bot_token' => 'token']);

        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create(['telegram_chat_id' => '999']);
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $scout = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'ASML',
            'latest_sma_20' => 100,
            'scout_rsi' => 55,
            'signal_low' => 95,
        ]);

        Event::dispatch(new SquadRadarTargetPosted($scout));

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return ($data['chat_id'] ?? null) === '999'
                && str_contains((string) ($data['text'] ?? ''), 'ASML');
        });
    }
}
