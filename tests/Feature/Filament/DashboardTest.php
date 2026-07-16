<?php

namespace Tests\Feature\Filament;

use App\Enums\Broker;
use App\Enums\EarningsReleaseHour;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BankrollUpdateWidget;
use App\Filament\Widgets\BuyStopReviewWidget;
use App\Filament\Widgets\OrderPlanTodayWidget;
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Models\Asset;
use App\Models\BankrollSnapshot;
use App\Models\Position;
use App\Models\User;
use App\Support\MarketDataFreshness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_dashboard_shows_buy_stop_review_widget_when_reviews_pending(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        Position::factory()->for($user)->scout()->requiringBuyStopReview()->create([
            'ticker' => 'APTV',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Dashboard::class)
            ->assertSee('Buy-stop review')
            ->assertSee('APTV');
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

    public function test_dashboard_widget_order_shows_portfolio_before_actions(): void
    {
        $widgets = (new Dashboard)->getWidgets();

        $this->assertSame([
            PortfolioExposureWidget::class,
            BankrollUpdateWidget::class,
            OrderPlanTodayWidget::class,
            PortfolioTopFlopWidget::class,
            PositionsRequiringActionWidget::class,
            BuyStopReviewWidget::class,
        ], $widgets);
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

        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $user->update(['trading_bankroll' => 10634.60]);

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
            ->assertSee('liquidatie');
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
            ->callAction('mark_as_updated', arguments: ['record' => $position->getKey()])
            ->assertDontSee($position->ticker);

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
            ->assertDontSee('Archiveer')
            ->assertDontSee('Close')
            ->assertActionVisible('mark_as_updated', arguments: ['record' => $updatePosition->getKey()])
            ->assertActionHidden('mark_as_updated', arguments: ['record' => $stoppedOut->getKey()]);
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
            ->assertActionVisible('mark_limit_placed', arguments: ['record' => $targetHit->getKey()])
            ->callAction('mark_limit_placed', arguments: ['record' => $targetHit->getKey()])
            ->assertDontSee('GS');
    }

    public function test_action_widget_shows_initial_stop_loss_after_activation(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $position = Position::factory()->for($user)->awaitingInitialSlPlacement()->create([
            'ticker' => 'NVDA',
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
            ->assertActionVisible('mark_initial_sl_placed', arguments: ['record' => $position->getKey()])
            ->callAction('mark_initial_sl_placed', arguments: ['record' => $position->getKey()])
            ->assertDontSee('NVDA');

        $this->assertNotNull($position->fresh()->initial_sl_placed_at);
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
            'latest_close_price' => 12.00,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(PositionsRequiringActionWidget::class)
            ->assertDontSee('TSLA')
            ->assertDontSee('Stel Limit Sell in op');
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
            ->assertActionVisible('hold_through_earnings', arguments: ['record' => $position->getKey()])
            ->callAction('hold_through_earnings', arguments: ['record' => $position->getKey()])
            ->assertDontSee('BAC');

        $position->refresh();

        $this->assertTrue($position->heldThroughEarningsForCurrentCycle());
        $this->assertNotNull($position->held_through_earnings_at);
    }
}
