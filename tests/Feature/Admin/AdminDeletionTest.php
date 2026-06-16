<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Admin\SquadResource\Pages\ListSquads;
use App\Filament\Resources\Admin\UserResource;
use App\Filament\Resources\Admin\UserResource\Pages\ListUsers;
use App\Models\Squad;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_can_delete_regular_user(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'fake@example.org']);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $target)
            ->assertNotified();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_super_admin_cannot_delete_self(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canDelete($admin));
    }

    public function test_super_admin_cannot_delete_other_super_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $otherAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin);

        $this->assertFalse(UserResource::canDelete($otherAdmin));
    }

    public function test_super_admin_can_delete_squad(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ['squad' => $squad] = $this->createUserWithSquad();

        $this->actingAs($admin);

        Livewire::test(ListSquads::class)
            ->callTableAction('delete', $squad)
            ->assertNotified();

        $this->assertDatabaseMissing('squads', ['id' => $squad->id]);
    }

    public function test_super_admin_can_bulk_delete_users(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $targets = User::factory()->count(2)->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('delete', $targets)
            ->assertNotified();

        foreach ($targets as $target) {
            $this->assertDatabaseMissing('users', ['id' => $target->id]);
        }
    }

    public function test_deleting_squad_owner_deletes_their_squad(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ['user' => $owner, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete', $owner)
            ->assertNotified();

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('squads', ['id' => $squad->id]);
    }
}
