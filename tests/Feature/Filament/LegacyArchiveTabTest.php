<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LegacyArchiveTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_archive_tab_is_hidden_when_disabled(): void
    {
        $this->authenticateFilament();

        config(['vestix.legacy_archive.enabled' => false]);

        Livewire::test(ListPositions::class)
            ->assertDontSee('Legacy Archief')
            ->assertSee('Open Posities')
            ->assertSee('Archief');
    }

    public function test_open_and_archive_tabs_exclude_legacy_positions(): void
    {
        config(['vestix.legacy_archive.enabled' => true]);

        $user = $this->authenticateFilament();

        $open = Position::factory()->for($user)->create([
            'ticker' => 'NEW1',
            'status' => 'open',
            'is_legacy' => false,
        ]);
        $closed = Position::factory()->for($user)->closed()->create([
            'ticker' => 'NEW2',
            'is_legacy' => false,
        ]);
        $legacyOpen = Position::factory()->for($user)->legacy()->create([
            'ticker' => 'OLD1',
            'status' => 'open',
        ]);
        $legacyClosed = Position::factory()->for($user)->closed()->legacy()->create([
            'ticker' => 'OLD2',
        ]);

        Livewire::test(ListPositions::class)
            ->assertSee('Legacy Archief')
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed, $legacyOpen, $legacyClosed]);

        Livewire::test(ListPositions::class)
            ->set('activeTab', 'closed')
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open, $legacyOpen, $legacyClosed]);

        Livewire::test(ListPositions::class)
            ->set('activeTab', 'legacy')
            ->assertCanSeeTableRecords([$legacyClosed])
            ->assertCanNotSeeTableRecords([$open, $closed, $legacyOpen]);
    }
}
