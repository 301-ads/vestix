<?php

namespace Tests\Unit;

use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Models\Position;
use App\Models\User;
use App\Services\CloneAttributionService;
use App\Services\SquadPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloneAttributionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_clone_outcome_rows_are_privacy_safe(): void
    {
        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create(['name' => 'Sniper Sam']);
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'NVDA',
        ]);

        $clone = $shared->cloneForUser($sniper);
        $clone->update([
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 1,
            'closed_at' => now(),
            'freeride_secured_at' => now(),
        ]);

        $rows = app(CloneAttributionService::class)->cloneOutcomeRows($shared->fresh());

        $this->assertCount(1, $rows);
        $this->assertSame('Sniper Sam', $rows->first()['cloner_name']);
        $this->assertSame('Gesloten', $rows->first()['status_label']);
        $this->assertTrue($rows->first()['freeride']);
        $this->assertSame(10.0, $rows->first()['roi_pct']);
        $this->assertArrayNotHasKey('pnl_dollars', $rows->first());
    }

    public function test_cloned_from_label_names_spotter(): void
    {
        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $analyst->update(['name' => 'Analyst Ann']);
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'AAPL',
        ]);

        $clone = $shared->cloneForUser($sniper);

        $label = app(CloneAttributionService::class)->clonedFromLabel($clone->fresh());

        $this->assertSame('Gekloond van Analyst Ann · AAPL', $label);
        $this->assertNull(app(CloneAttributionService::class)->clonedFromLabel($shared));
    }
}
