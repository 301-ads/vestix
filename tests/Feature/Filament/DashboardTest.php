<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PositionsRequiringUpdateWidget;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_force_sync_action(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertActionVisible('sync_api');
    }

    public function test_force_sync_starts_background_process(): void
    {
        Process::fake();

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->callAction('sync_api');

        Process::assertRan(function ($process) use ($user) {
            return $process->command === [
                PHP_BINARY,
                base_path('artisan'),
                'swng:fetch-data',
                '--user-id='.$user->id,
            ];
        });
    }

    public function test_force_sync_does_not_leave_stale_sync_flag_before_command_runs(): void
    {
        Process::fake();

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->callAction('sync_api');

        $this->assertFalse(\App\Support\MarketDataFreshness::isSyncInProgress());
    }

    public function test_force_sync_stores_database_notification(): void
    {
        Process::fake();

        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->callAction('sync_api');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
        ]);

        $notification = $user->notifications()->first();

        $this->assertSame('API-sync gestart', $notification->data['title']);
    }

    public function test_dashboard_shows_action_widget_on_page(): void
    {
        $user = User::factory()->create();

        Position::factory()->create([
            'ticker' => 'WDC',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(Dashboard::class)
            ->assertSee('Actie vereist')
            ->assertSee('WDC');
    }

    public function test_action_widget_lists_only_positions_requiring_update(): void
    {
        $user = User::factory()->create();

        $updatePosition = Position::factory()->create([
            'ticker' => 'WDC',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $holdPosition = Position::factory()->create([
            'ticker' => 'HOLD',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(PositionsRequiringUpdateWidget::class)
            ->assertCanSeeTableRecords([$updatePosition])
            ->assertCanNotSeeTableRecords([$holdPosition]);
    }

    public function test_action_widget_mark_as_updated_removes_position_from_list(): void
    {
        $user = User::factory()->create();

        $position = Position::factory()->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAs($user);

        Livewire::test(PositionsRequiringUpdateWidget::class)
            ->assertCanSeeTableRecords([$position])
            ->callTableAction('mark_as_updated', $position)
            ->assertCanNotSeeTableRecords([$position->fresh()]);

        $this->assertEquals(76.10, (float) $position->fresh()->current_sl);
    }
}
