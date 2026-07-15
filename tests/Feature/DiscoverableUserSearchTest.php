<?php

namespace Tests\Feature;

use App\Enums\Broker;
use App\Enums\SquadRole;
use App\Filament\Pages\EditUserProfile;
use App\Filament\Pages\RegisterSquad;
use App\Models\Squad;
use App\Models\User;
use App\Services\DiscoverableUserSearchService;
use App\Services\SquadManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiscoverableUserSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_only_discoverable_users(): void
    {
        $actor = User::factory()->create(['name' => 'Actor']);
        $visible = User::factory()->create(['name' => 'Visible Trader', 'email' => 'visible@vestix.test']);
        User::factory()->notDiscoverable()->create(['name' => 'Hidden Trader', 'email' => 'hidden@vestix.test']);

        $results = app(DiscoverableUserSearchService::class)->search('Trader', $actor);

        $this->assertArrayHasKey($visible->id, $results);
        $this->assertCount(1, $results);
    }

    public function test_search_excludes_actor_and_existing_squad_members(): void
    {
        ['user' => $actor, 'squad' => $squad] = $this->createUserWithSquad();
        $candidate = User::factory()->create(['name' => 'Candidate', 'email' => 'candidate@vestix.test']);
        $squad->users()->attach($candidate->id);

        $results = app(DiscoverableUserSearchService::class)->search('Candidate', $actor, $squad);

        $this->assertSame([], $results);
    }

    public function test_search_requires_minimum_query_length(): void
    {
        $actor = User::factory()->create();
        User::factory()->create(['name' => 'Alpha']);

        $results = app(DiscoverableUserSearchService::class)->search('A', $actor);

        $this->assertSame([], $results);
    }

    public function test_register_squad_can_add_selected_members(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create(['email' => 'member@vestix.test']);

        $this->actingAsFilamentUser($creator);

        Livewire::test(RegisterSquad::class)
            ->fillForm([
                'name' => 'Alpha Squad',
                'member_ids' => [$member->id],
                'default_member_role' => SquadRole::Scout->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $squad = Squad::query()->where('name', 'Alpha Squad')->first();

        $this->assertNotNull($squad);
        $this->assertTrue($squad->users()->whereKey($member->id)->exists());
    }

    public function test_profile_privacy_toggle_hides_user_from_search(): void
    {
        $user = User::factory()->create(['name' => 'Private Trader']);
        $searcher = User::factory()->create();

        $this->actingAsFilamentUser($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'is_discoverable' => false,
                'primary_broker' => Broker::Revolut->value,
                'default_risk_percent' => '1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $results = app(DiscoverableUserSearchService::class)->search('Private', $searcher);

        $this->assertSame([], $results);
    }

    public function test_email_invite_still_works_for_non_discoverable_user(): void
    {
        ['user' => $commander, 'squad' => $squad] = $this->createUserWithSquad();
        $hidden = User::factory()->notDiscoverable()->create(['email' => 'hidden@vestix.test']);

        $member = app(SquadManagementService::class)->addMember(
            $squad,
            $hidden->email,
            SquadRole::Sniper,
        );

        $this->assertTrue($member->is($hidden));
        $this->assertTrue($squad->fresh()->users()->whereKey($hidden->id)->exists());
    }
}
