<?php

namespace Tests\Feature\Filament;

use App\Enums\BrokerOrderStatus;
use App\Enums\ScoutPipelineStatus;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Livewire\ExecutionPlanPanel;
use App\Models\Position;
use App\Services\SmartAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmartBudgetAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocate_budget_bulk_action_removed_from_radar(): void
    {
        $this->authenticateFilament();

        Livewire::test(ListScouts::class)
            ->assertTableBulkActionDoesNotExist('allocate_budget');
    }

    public function test_execution_plan_panel_applies_quantity_without_activating(): void
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
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanPanel::class)
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
        ]);

        $a = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAA',
            'last_setup_score' => 10,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLK',
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        $b = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BBB',
            'last_setup_score' => 6,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanPanel::class)
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
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLV',
            'quantity' => 1,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanPanel::class)
            ->call('applyAllocation');

        $scout->refresh();

        $this->assertGreaterThan(1, (int) $scout->quantity);
        $this->assertNotNull($scout->risk_budget);
    }

    public function test_remove_from_plan_clears_reminder_and_updates_badge(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'RPRX',
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanPanel::class)
            ->assertSee('RPRX')
            ->call('removeFromPlan', $scout->id)
            ->assertDontSee('RPRX');

        $this->assertNull($scout->fresh()->market_open_reminder_on);
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
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
            'market_open_reminder_on' => now('Europe/Amsterdam')->toDateString(),
        ]);

        Livewire::test(ExecutionPlanPanel::class)
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

    public function test_radar_toggle_adds_scout_to_order_plan(): void
    {
        $user = $this->authenticateFilament();

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'entry_price' => 42.00,
            'latest_sma_20' => 40.00,
            'latest_atr_14' => 1.00,
            'market_open_reminder_on' => null,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableAction('toggle_market_open_reminder', $scout);

        $this->assertNotNull($scout->fresh()->market_open_reminder_on);
        $this->assertSame(1, Position::orderPlanForUser((int) $user->id)->count());
    }
}
