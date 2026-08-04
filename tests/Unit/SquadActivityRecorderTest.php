<?php

namespace Tests\Unit;

use App\Enums\PositionVisibility;
use App\Enums\SquadActivityType;
use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\SquadActivity;
use App\Models\User;
use App\Services\SquadActivityRecorder;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SquadActivityRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_share_clone_and_closed_outcomes_privacy_safe(): void
    {
        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create(['name' => 'Clone Kid']);
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'FEED',
        ]);

        $recorder = app(SquadActivityRecorder::class);
        $recorder->recordShare($shared);

        $clone = $shared->cloneForUser($sniper);

        $clone->update([
            'status' => 'closed',
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 1,
            'closed_at' => now(),
        ]);

        $recorder->recordClosed($clone->fresh());

        $this->assertDatabaseHas('squad_activities', [
            'squad_id' => $squad->id,
            'type' => SquadActivityType::Shared->value,
            'ticker' => 'FEED',
        ]);
        $this->assertDatabaseHas('squad_activities', [
            'squad_id' => $squad->id,
            'type' => SquadActivityType::Cloned->value,
            'actor_user_id' => $sniper->id,
        ]);

        $closed = SquadActivity::query()
            ->where('type', SquadActivityType::Closed)
            ->firstOrFail();

        $this->assertEqualsWithDelta(10.0, (float) $closed->meta['roi_pct'], 0.01);
        $this->assertStringContainsString('10.00%', $closed->summary());
        $this->assertStringNotContainsString('$', $closed->summary());
    }

    public function test_private_open_is_not_recorded(): void
    {
        ['user' => $user] = $this->createUserWithSquad();

        $position = Position::factory()->for($user)->create([
            'status' => 'open',
            'visibility' => PositionVisibility::Private,
            'squad_id' => null,
            'ticker' => 'SILENT',
        ]);

        app(SquadActivityRecorder::class)->recordOpened($position);

        $this->assertDatabaseMissing('squad_activities', ['ticker' => 'SILENT']);
    }
}
