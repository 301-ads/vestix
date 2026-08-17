<?php

namespace Tests\Feature\Filament;

use App\Enums\Broker;
use App\Enums\EarningsReleaseHour;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BankrollUpdateWidget;
use App\Filament\Widgets\FirstRunChecklistWidget;
use App\Filament\Widgets\OrderPlanTodayWidget;
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Filament\Widgets\SetupRadarWidget;
use App\Models\Asset;
use App\Models\BankrollSnapshot;
use App\Models\Position;
use App\Models\User;
use App\Support\MarketDataFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

    public function test_dashboard_header_keeps_freshness_beside_sync_on_one_row(): void
    {
        Cache::put(
            'vestix:last_api_fetch',
            now()->subDay()->toIso8601String(),
        );

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        $this->assertSame(
            ['vestix-dashboard', 'vestix-dashboard--today'],
            (new Dashboard)->getPageClasses(),
        );

        $this->get('/admin')
            ->assertOk()
            ->assertSee('vestix-dashboard', false)
            ->assertSee('vestix-market-data-status', false)
            ->assertSee(MarketDataFreshness::subheading())
            ->assertSee('Forceer API Sync');
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

    public function test_dashboard_shows_buy_stop_review_in_actions_when_reviews_pending(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        Position::factory()->for($user)->scout()->requiringBuyStopReview()->create([
            'ticker' => 'APTV',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->assertSee('Acties vereist')
            ->assertSee('APTV')
            ->assertSee('Beoordeel open buy-stop')
            ->assertDontSee('Buy-stop review');
    }

    public function test_action_widget_lists_only_positions_requiring_update(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $updatePosition = Position::factory()->for($user)->create([
            'ticker' => 'WDC',
            'entry_price' => 78.00,
            'initial_sl' => 74.50,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $holdPosition = Position::factory()->for($user)->create([
            'ticker' => 'HOLD',
            'entry_price' => 78.00,
            'initial_sl' => 74.50,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 75.00,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('WDC')
            ->assertDontSee('HOLD')
            ->assertSee('Verhoog Stop-Loss van')
            ->assertSee('$76.10')
            ->assertDontSee('Doelprijs')
            ->assertDontSee('Status');
    }

    public function test_dashboard_uses_dashboard_title(): void
    {
        $this->assertSame('Dashboard', (new Dashboard)->getTitle());
    }

    public function test_dashboard_widget_order_shows_actions_before_portfolio(): void
    {
        $widgets = (new Dashboard)->getWidgets();

        $this->assertSame([
            FirstRunChecklistWidget::class,
            PositionsRequiringActionWidget::class,
            BankrollUpdateWidget::class,
            PortfolioExposureWidget::class,
            PortfolioTopFlopWidget::class,
            SetupRadarWidget::class,
            OrderPlanTodayWidget::class,
        ], $widgets);
    }

    public function test_portfolio_and_setup_radar_share_dashboard_row(): void
    {
        $dashboard = new Dashboard;

        $this->assertSame([
            'default' => 1,
            'lg' => 2,
        ], $dashboard->getColumns());

        $this->assertSame([
            'default' => 'full',
            'lg' => 1,
        ], (new PortfolioTopFlopWidget)->getColumnSpan());

        $this->assertSame([
            'default' => 'full',
            'lg' => 1,
        ], (new SetupRadarWidget)->getColumnSpan());
    }

    public function test_dashboard_does_not_show_alpha_tracker_widgets(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-01-04',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10635,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 520,
            'recorded_on' => '2026-01-11',
            'recorded_at' => now(),
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->assertDontSee('Jouw Rendement (YTD)')
            ->assertDontSee('Jouw Alpha');
    }

    public function test_dashboard_shows_bankroll_update_widget_when_weekly_update_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00', 'Europe/Amsterdam'));

        config([
            'vestix.ibkr.reader' => 'stub',
            'vestix.bankroll_tracker.source' => 'manual',
        ]);

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update([
            'trading_bankroll' => 10634.60,
            'ibkr_last_success_at' => null,
            'ibkr_data_stale' => false,
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->assertSee('Wekelijkse Bankroll Update');
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
            ->assertSee('STOP')
            ->assertSee('liquidatie')
            ->assertSee('Sluiten')
            ->assertTableActionVisible('archive', $stoppedOut)
            ->assertTableActionHidden('mark_as_updated', $stoppedOut);
    }

    public function test_action_widget_excludes_hold_positions_when_sl_is_up_to_date(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $holdPosition = Position::factory()->for($user)->create([
            'ticker' => 'CDNS',
            'entry_price' => 350.00,
            'initial_sl' => 51.15,
            'quantity' => 10,
            'latest_close_price' => 400.00,
            'latest_sma_20' => 51.71,
            'latest_atr_14' => 1.13,
            'current_sl' => 51.15,
            'status' => 'open',
        ]);

        $this->assertSame('HOLD', $holdPosition->action_command);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertDontSee('CDNS');
    }

    public function test_action_widget_mark_as_updated_removes_position_from_list(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $position = Position::factory()->for($user)->create([
            'entry_price' => 78.00,
            'initial_sl' => 74.50,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee($position->ticker)
            ->callTableAction('mark_as_updated', $position)
            ->assertDontSee($position->ticker);

        $this->assertEquals(76.10, (float) $position->fresh()->current_sl);
    }

    public function test_action_widget_update_actions_are_callable_per_row(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $attrs = [
            'entry_price' => 78.00,
            'initial_sl' => 74.50,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 74.50,
            'status' => 'open',
        ];

        $positions = collect(['ALL', 'HALO', 'KVUE'])->map(
            fn (string $ticker) => Position::factory()->for($user)->create([...$attrs, 'ticker' => $ticker])
        );

        $this->actingAsFilamentUser($user, $squad);

        $component = Livewire::test(PositionsRequiringActionWidget::class);

        foreach ($positions as $position) {
            $component->assertTableActionVisible('mark_as_updated', $position);
        }

        foreach ($positions as $position) {
            Livewire::test(PositionsRequiringActionWidget::class)
                ->callTableAction('mark_as_updated', $position);

            $this->assertEquals(76.10, (float) $position->fresh()->current_sl);
        }
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

    public function test_action_widget_shows_archive_only_for_earnings_and_liquidation(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $stoppedOut = Position::factory()->for($user)->create([
            'ticker' => 'STOP',
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
            ->assertSee('UPD')
            ->assertSee('STOP')
            ->assertSee('Update')
            ->assertDontSee('Close')
            ->assertTableActionVisible('mark_as_updated', $updatePosition)
            ->assertTableActionHidden('mark_as_updated', $stoppedOut)
            ->assertTableActionHidden('archive', $updatePosition)
            ->assertTableActionVisible('archive', $stoppedOut)
            ->callTableAction('archive', $stoppedOut, data: [
                'exit_price' => 74.50,
            ])
            ->assertDontSee('STOP');

        $this->assertSame('closed', $stoppedOut->fresh()->status);
    }

    public function test_action_widget_shows_limit_sell_instruction_and_update_action(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $targetHit = Position::factory()->for($user)->create([
            'ticker' => 'GS',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('GS')
            ->assertSee('Target 1 bereikt op')
            ->assertSee('$12.00')
            ->assertSee('Pas SL aan, verkoop 50%')
            ->assertTableActionVisible('mark_limit_placed', $targetHit)
            ->callTableAction('mark_limit_placed', $targetHit)
            ->assertDontSee('GS');
    }

    public function test_action_widget_shows_initial_stop_loss_after_activation(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $position = Position::factory()->for($user)->awaitingInitialSlPlacement()->create([
            'ticker' => 'NVDA',
            'broker' => Broker::Revolut,
            'entry_price' => 79.50,
            'initial_sl' => 76.10,
            'quantity' => 12,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'current_sl' => 76.10,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('NVDA')
            ->assertSee('Stel Stop-Loss in op')
            ->assertSee('$76.10')
            ->assertTableActionVisible('mark_initial_sl_placed', $position)
            ->callTableAction('mark_initial_sl_placed', $position)
            ->assertDontSee('NVDA');

        $this->assertNotNull($position->fresh()->initial_sl_placed_at);
    }

    public function test_action_widget_hides_initial_stop_loss_for_ibkr_bracket_positions(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update(['primary_broker' => Broker::Ibkr]);

        // current_sl already matches computed SL so only the initial-SL todo would apply — which IBKR suppresses.
        Position::factory()->for($user)->awaitingInitialSlPlacement()->create([
            'ticker' => 'ALL',
            'broker' => Broker::Ibkr,
            'entry_price' => 245.40,
            'initial_sl' => 238.00,
            'quantity' => 3,
            'latest_close_price' => 245.00,
            'latest_sma_20' => 240.00,
            'latest_atr_14' => 4.00,
            'current_sl' => 238.00,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('ALL')
            ->assertDontSee('Stel Stop-Loss in op')
            ->assertSee('Wijzig Take Profit van 100%')
            ->assertSee('2 stuks (50%)');
    }

    public function test_action_widget_shows_limit_sell_instruction_for_non_revolut_broker(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update(['primary_broker' => Broker::None]);

        Position::factory()->for($user)->create([
            'ticker' => 'IBM',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('IBM')
            ->assertSee('Stel Limit Sell in op')
            ->assertSee('voor 50% van je positie.');
    }

    public function test_action_widget_hides_limit_sell_for_ibkr_tagged_position(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update(['primary_broker' => Broker::Revolut]);

        Position::factory()->for($user)->create([
            'broker' => Broker::Ibkr,
            'ticker' => 'TSLA',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.50,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('TSLA')
            ->assertDontSee('Stel Limit Sell in op')
            ->assertSee('Wijzig Take Profit van 100%')
            ->assertSee('50 stuks (50%)');
    }

    public function test_action_widget_confirms_ibkr_take_profit_qty_adjust(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update(['primary_broker' => Broker::Ibkr]);

        $position = Position::factory()->for($user)->create([
            'ticker' => 'SBLK',
            'broker' => Broker::Ibkr,
            'entry_price' => 28.07,
            'initial_sl' => 26.80,
            'current_sl' => 26.80,
            'latest_close_price' => 29.00,
            'latest_sma_20' => 26.80,
            'latest_atr_14' => 0.80,
            'quantity' => 41,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('SBLK')
            ->assertSee('21 stuks (50%)')
            ->assertTableActionVisible('adjust_target_1', $position)
            ->callTableAction('adjust_target_1', $position)
            ->assertDontSee('SBLK');

        $this->assertNotNull($position->fresh()->target_1_qty_adjusted_at);
    }

    public function test_action_widget_confirms_ibkr_runner_stop_after_target_1_fill(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update(['primary_broker' => Broker::Ibkr]);

        $position = Position::factory()->for($user)->create([
            'ticker' => 'EC',
            'broker' => Broker::Ibkr,
            'entry_price' => 16.58,
            'initial_sl' => 15.99,
            'current_sl' => 15.99,
            'latest_close_price' => 17.80,
            'latest_sma_20' => 16.20,
            'latest_atr_14' => 0.42,
            'quantity' => 92,
            'status' => 'open',
            'target_1_qty_adjusted_at' => now(),
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('EC')
            ->assertSee('Plaats een nieuwe Stop-Loss op')
            ->assertSee('$16.58')
            ->assertSee('46 stuks (runner)')
            ->assertDontSee('Stel Limit Sell in op')
            ->assertTableActionVisible('place_runner_sl', $position)
            ->callTableAction('place_runner_sl', $position)
            ->assertDontSee('EC');

        $fresh = $position->fresh();
        $this->assertNotNull($fresh->runner_sl_placed_at);
        $this->assertEquals(16.58, (float) $fresh->current_sl);
    }

    public function test_action_widget_hides_limit_sell_for_auto_runner_bypass_position(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'latest_sma_20' => 57.00,
            'latest_atr_14' => 1.50,
            'quantity' => 22,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertDontSee('BAC')
            ->assertDontSee('Stel Limit Sell in op');
    }

    public function test_action_widget_shows_hold_through_earnings_for_overdue_bmo_position(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14', 'Europe/Amsterdam'));

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        $position = Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'latest_sma_20' => 57.00,
            'latest_atr_14' => 1.50,
            'quantity' => 22,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('BAC')
            ->assertSee('Earnings-exit (14 juli) is te laat')
            ->assertSee('Doorgaan als runner')
            ->assertSee('Archiveer')
            ->assertTableActionVisible('hold_through_earnings', $position)
            ->assertTableActionVisible('archive', $position)
            ->callTableAction('hold_through_earnings', $position)
            ->assertDontSee('BAC');

        $position->refresh();

        $this->assertTrue($position->heldThroughEarningsForCurrentCycle());
        $this->assertNotNull($position->held_through_earnings_at);
    }

    public function test_action_widget_empty_state_is_compact_header_with_gray_zero_badge(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertSee('Acties vereist')
            ->assertSeeHtml('bg-gray-500/10 text-gray-400 ring-gray-500/20')
            ->assertSeeHtml('>0</span>')
            ->assertSeeHtml('vestix-actions-empty--compact')
            ->assertDontSee('Geen acties vereist')
            ->assertDontSee('Alle stop-losses zijn up-to-date');
    }
}
