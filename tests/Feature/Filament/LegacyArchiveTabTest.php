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

    public function test_open_and_archive_tabs_exclude_legacy_positions(): void
    {
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
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed, $legacyOpen, $legacyClosed]);

        Livewire::test(ListPositions::class)
            ->set('activeTab', 'closed')
            ->assertCanSeeTableRecords([$closed])
            ->assertCanNotSeeTableRecords([$open, $legacyOpen, $legacyClosed]);

        Livewire::test(ListPositions::class)
            ->set('activeTab', 'legacy')
            ->assertCanSeeTableRecords([$legacyOpen, $legacyClosed])
            ->assertCanNotSeeTableRecords([$open, $closed]);
    }
}
