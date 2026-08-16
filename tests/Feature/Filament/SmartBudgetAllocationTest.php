<?php

namespace Tests\Feature\Filament;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\ScoutPipelineStatus;
use App\Enums\ScoutReviewStatus;
use App\Enums\ScoutSource;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Filament\Widgets\OrderPlanTodayWidget;
use App\Livewire\ExecutionPlanContent;
use App\Livewire\ExecutionPlanPanel;
use App\Models\Position;
use App\Services\SmartAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmartBudgetAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vestix.ibkr.reader' => 'stub',
            'vestix.ibkr.block_automation_when_stale' => true,
        ]);
    }

    public function test_allocate_budget_bulk_action_removed_from_radar(): void
    {
        $this->authenticateFilament();

        Livewire::test(ListScouts::class)
            ->assertTableBulkActionDoesNotExist('allocate_budget');
    }

    public function test_execution_plan_content_applies_quantity_without_activating(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        $a = Position::factory()->for($user)->scout()->create([
            'ticker' => 'RPRX',
            'last_setup_score' => 10,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $b = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EWTX',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->assertSet('mode', SmartAllocationService::MODE_SMART)
            ->assertSee('RPRX')
            ->assertSee('EWTX')
            ->call('applyAllocation');

        $a->refresh();
        $b->refresh();

        $this->assertNotSame(5, (int) $a->quantity);
        $this->assertNotNull($a->risk_budget);
        $this->assertGreaterThan((float) $b->risk_budget, (float) $a->risk_budget);
        $this->assertSame(BrokerOrderStatus::Scout, $a->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Pending, $a->scoutPipelineStatus());
        $this->assertSame(BrokerOrderStatus::Scout, $b->broker_order_status);
    }

    public function test_execution_plan_equal_mode_splits_evenly(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
            'primary_broker' => Broker::Ibkr,
        ]);

        $a = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAA',
            'last_setup_score' => 10,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $b = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BBB',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->call('setMode', SmartAllocationService::MODE_EQUAL)
            ->call('applyAllocation');

        $this->assertEqualsWithDelta(
            (float) $a->fresh()->risk_budget,
            (float) $b->fresh()->risk_budget,
            0.5,
        );
    }

    public function test_execution_plan_works_with_single_scout(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'last_setup_score' => 9,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLV',
            'quantity' => 1,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->call('applyAllocation');

        $scout->refresh();

        $this->assertGreaterThan(1, (int) $scout->quantity);
        $this->assertNotNull($scout->risk_budget);
    }

    public function test_remove_from_plan_clears_reminder(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'RPRX',
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->assertSee('RPRX')
            ->call('removeFromPlan', $scout->id)
            ->assertDontSee('RPRX');

        $this->assertNull($scout->fresh()->market_open_reminder_on);
    }

    public function test_dashboard_widget_shows_compact_decision_table(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'last_setup_score' => 10,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLV',
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $this->assertTrue(OrderPlanTodayWidget::canView());

        Livewire::test(ExecutionPlanContent::class, ['layout' => 'embedded'])
            ->assertSee('COO')
            ->assertSee('R/R')
            ->assertSee('Risico %')
            ->assertSeeHtml('vestix-smart-allocation__ticker-link')
            ->assertSeeHtml('vestix-execution-plan__remove-btn')
            ->assertDontSee('Buy-Stop')
            ->assertDontSee('Take-Profit')
            ->assertSee('Toepassen');

        Livewire::test(Dashboard::class)
            ->assertSee('Order Plan vandaag')
            ->assertSee('COO')
            ->assertSee('R/R')
            ->assertSee('Risico %')
            ->assertDontSee('Open Executie Paneel');
    }

    public function test_dashboard_keeps_order_plan_widget_when_empty(): void
    {
        $this->authenticateFilament();

        $this->assertTrue(OrderPlanTodayWidget::canView());

        Livewire::test(Dashboard::class)
            ->assertSee('Order Plan vandaag')
            ->assertSee('Nog geen setups in je Order Plan');

        Livewire::test(ExecutionPlanContent::class, ['layout' => 'embedded'])
            ->assertSee('Nog geen setups in je Order Plan')
            ->assertSee('winkelwagen-icoon');
    }

    public function test_removing_last_scout_keeps_empty_state_without_error(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->assertSee('COO')
            ->call('removeFromPlan', $scout->id)
            ->assertDontSee('COO')
            ->assertSee('Nog geen setups in je Order Plan')
            ->assertHasNoErrors();
    }

    public function test_order_plan_row_exposes_place_order_action(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'last_setup_score' => 10,
            'entry_price' => 71.80,
            'latest_close_price' => 71.80,
            'latest_sma_20' => 70.30,
            'latest_atr_14' => 4.05,
            'quantity' => 11,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->assertSee('COO')
            ->assertSee('Order geplaatst');
    }

    public function test_topbar_panel_embeds_same_content_component(): void
    {
        $this->authenticateFilament();

        Livewire::test(ExecutionPlanPanel::class)
            ->assertSeeLivewire(ExecutionPlanContent::class);
    }

    public function test_apply_allocation_approves_pending_visual_review(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
            'primary_broker' => Broker::Ibkr,
        ]);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SBLK',
            'source' => ScoutSource::SniperScan,
            'review_status' => ScoutReviewStatus::PendingVisualReview,
            'broker' => Broker::Ibkr,
            'last_setup_score' => 10,
            'entry_price' => 20.00,
            'latest_sma_20' => 19.50,
            'latest_atr_14' => 0.50,
            'latest_close_price' => 20.00,
            'sector_etf' => 'XLI',
            'quantity' => 1,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $this->assertFalse($scout->isPendingVisualReview());

        Livewire::test(ExecutionPlanContent::class)
            ->call('applyAllocation');

        $scout->refresh();

        $this->assertSame(ScoutReviewStatus::ActiveScout, $scout->review_status);
        $this->assertGreaterThan(1, (int) $scout->quantity);
        $this->assertTrue($scout->canMarkBuyStopPlaced());

        Livewire::test(ListScouts::class)
            ->assertTableActionEnabled('mark_buy_stop_placed', $scout);
    }

    public function test_mark_buy_stop_after_allocation_activates_with_new_quantity(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        $a = Position::factory()->for($user)->scout()->create([
            'ticker' => 'RPRX',
            'last_setup_score' => 10,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'quantity' => 5,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $b = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EWTX',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->call('applyAllocation');

        $a->refresh();
        $allocatedQty = (int) $a->quantity;

        Livewire::test(ListScouts::class)
            ->callTableAction('mark_buy_stop_placed', $a);

        $a->refresh();

        $this->assertSame($allocatedQty, (int) $a->quantity);
        $this->assertSame(BrokerOrderStatus::Pending, $a->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Active, $a->scoutPipelineStatus());
    }

    public function test_reallocate_after_active_orders_uses_remaining_pie_only(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'JNJ',
            'last_setup_score' => 9,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'quantity' => 10,
            'risk_budget' => 30.00,
            'broker_order_status' => BrokerOrderStatus::Pending,
            'market_open_reminder_on' => null,
        ]);

        $pending = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EMBJ',
            'last_setup_score' => 9,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'quantity' => 1,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->assertSee('al actief')
            ->assertSee('beschikbaar')
            ->call('applyAllocation');

        $pending->refresh();

        $this->assertEqualsWithDelta(70.0, (float) $pending->risk_budget, 0.1);
        $this->assertSame(23, (int) $pending->quantity);
    }

    public function test_apply_excludes_scouts_when_open_risk_on_in_same_sector(): void
    {
        $user = $this->authenticateFilament();
        $user->update([
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'status' => 'open',
            'sector_etf' => 'XLF',
            'entry_price' => 100.00,
            'current_sl' => 95.00,
            'quantity' => 10,
            'latest_close_price' => 102.00,
        ]);

        $sfnc = Position::factory()->for($user)->scout()->create([
            'ticker' => 'SFNC',
            'last_setup_score' => 9,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $tfc = Position::factory()->for($user)->scout()->create([
            'ticker' => 'TFC',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $aapl = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAPL',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_close_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanContent::class)
            ->assertSee('correlatierisico')
            ->assertSee('SFNC')
            ->assertSee('TFC')
            ->call('applyAllocation');

        $this->assertTrue($sfnc->fresh()->isOrderPlanExcludedToday());
        $this->assertTrue($tfc->fresh()->isOrderPlanExcludedToday());
        $this->assertNotNull($aapl->fresh()->risk_budget);
        $this->assertFalse($aapl->fresh()->isOrderPlanExcludedToday());
    }

    public function test_radar_toggle_adds_scout_to_order_plan(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'entry_price' => 42.00,
            'latest_close_price' => 42.00,
            'latest_sma_20' => 40.00,
            'latest_atr_14' => 1.00,
            'market_open_reminder_on' => null,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('toggle_market_open_reminder', $scout);

        $this->assertNotNull($scout->fresh()->market_open_reminder_on);
        $this->assertSame(1, Position::orderPlanForUser((int) $user->id)->count());
    }

    public function test_bulk_add_to_order_plan_schedules_reminder_not_broker_submit(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'CART',
            'entry_price' => 55.00,
            'latest_close_price' => 54.00,
            'latest_sma_20' => 52.00,
            'latest_atr_14' => 1.20,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => null,
            'broker_submitted_at' => null,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableBulkAction('add_to_order_plan', [$scout]);

        $scout->refresh();

        $this->assertNotNull($scout->market_open_reminder_on);
        $this->assertSame(BrokerOrderStatus::Scout, $scout->broker_order_status);
        $this->assertNull($scout->broker_submitted_at);
        $this->assertSame(1, Position::orderPlanForUser((int) $user->id)->count());
        $this->assertSame(0, Position::activeOrderPlanForUser((int) $user->id)->count());
    }

    public function test_weekend_toggle_keeps_scout_visible_in_order_plan_cart(): void
    {
        \Illuminate\Support\Carbon::setTestNow(
            \Illuminate\Support\Carbon::parse('2026-08-16 10:00:00', 'Europe/Amsterdam'),
        );

        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'WKND',
            'entry_price' => 33.00,
            'latest_close_price' => 32.50,
            'latest_sma_20' => 31.00,
            'latest_atr_14' => 0.80,
            'market_open_reminder_on' => null,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('toggle_market_open_reminder', $scout);

        $scout->refresh();

        // Zondag → reminder op maandag, maar winkelwagen toont alle geplande scouts.
        $this->assertSame('2026-08-17', $scout->market_open_reminder_on?->toDateString());
        $this->assertSame(1, Position::orderPlanForUser((int) $user->id)->count());

        Livewire::test(ExecutionPlanContent::class)
            ->assertDontSee('Nog geen setups in je Order Plan')
            ->assertSee('WKND');

        \Illuminate\Support\Carbon::setTestNow();
    }
}
