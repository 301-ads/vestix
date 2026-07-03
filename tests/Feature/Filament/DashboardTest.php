<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Models\Position;
use App\Models\User;
use App\Support\MarketDataFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_force_sync_action(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertActionVisible('sync_api');
    }

    public function test_force_sync_starts_background_process(): void
    {
        Process::fake();

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->callAction('sync_api');

        Process::assertRan(function ($process) use ($user) {
            return $process->command === [
                PHP_BINARY,
                base_path('artisan'),
                'vestix:fetch-data',
                '--user-id='.$user->id,
            ];
        });
    }

    public function test_force_sync_does_not_leave_stale_sync_flag_before_command_runs(): void
    {
        Process::fake();

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->callAction('sync_api');

        $this->assertFalse(MarketDataFreshness::isSyncInProgress());
    }

    public function test_force_sync_stores_database_notification(): void
    {
        Process::fake();

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

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
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        Position::factory()->for($user)->create([
            'ticker' => 'WDC',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->assertSee('Acties vereist')
            ->assertSee('WDC');
    }

    public function test_action_widget_lists_only_positions_requiring_update(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $updatePosition = Position::factory()->for($user)->create([
            'ticker' => 'WDC',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $holdPosition = Position::factory()->for($user)->create([
            'ticker' => 'HOLD',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertCanSeeTableRecords([$updatePosition])
            ->assertCanNotSeeTableRecords([$holdPosition])
            ->assertSee('$76.10')
            ->assertSee('fi-copyable');
    }

    public function test_action_widget_lists_stopped_out_positions_with_liquidation_type(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $stoppedOut = Position::factory()->for($user)->create([
            'ticker' => 'STOP',
            'latest_close_price' => 74.50,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertCanSeeTableRecords([$stoppedOut])
            ->assertSee('Liquidatie')
            ->assertSee('STOP');
    }

    public function test_action_widget_excludes_hold_positions_when_sl_is_up_to_date(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $holdPosition = Position::factory()->for($user)->create([
            'ticker' => 'CDNS',
            'latest_close_price' => 400.00,
            'latest_sma_20' => 51.71,
            'latest_atr_14' => 1.13,
            'current_sl' => 51.15,
            'status' => 'open',
        ]);

        $this->assertSame('HOLD', $holdPosition->action_command);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertCanNotSeeTableRecords([$holdPosition])
            ->assertDontSee('CDNS');
    }

    public function test_action_widget_mark_as_updated_removes_position_from_list(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $position = Position::factory()->for($user)->create([
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertCanSeeTableRecords([$position])
            ->callTableAction('mark_as_updated', $position)
            ->assertCanNotSeeTableRecords([$position->fresh()]);

        $this->assertEquals(76.10, (float) $position->fresh()->current_sl);
    }

    public function test_portfolio_widget_shows_locked_profit_per_position(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $lockedPosition = Position::factory()->for($user)->create([
            'ticker' => 'ASML',
            'entry_price' => 875.00,
            'current_sl' => 1500.00,
            'quantity' => 2,
            'latest_close_price' => 1600.00,
            'status' => 'open',
        ]);

        $unlockedPosition = Position::factory()->for($user)->create([
            'ticker' => 'SNDK',
            'entry_price' => 80.00,
            'current_sl' => 74.50,
            'quantity' => 10,
            'latest_close_price' => 85.00,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PortfolioTopFlopWidget::class)
            ->assertCanSeeTableRecords([$lockedPosition, $unlockedPosition])
            ->assertSee('Locked')
            ->assertSee('+$1,250.00')
            ->assertSee('Geen lock');
    }

    public function test_admin_panel_includes_pwa_pull_to_refresh_script(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('pwa-pull-to-refresh', false);
    }

    public function test_action_widget_does_not_show_archive_action(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $stoppedOut = Position::factory()->for($user)->create([
            'latest_close_price' => 74.50,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $updatePosition = Position::factory()->for($user)->create([
            'ticker' => 'UPD',
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertCanSeeTableRecords([$stoppedOut, $updatePosition])
            ->assertSee('Update')
            ->assertDontSee('Archiveer')
            ->assertDontSee('Close')
            ->assertTableActionVisible('mark_as_updated', $updatePosition)
            ->assertTableActionHidden('mark_as_updated', $stoppedOut);
    }
}
