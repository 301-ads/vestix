<?php

namespace Tests\Feature\Filament;

use App\Enums\BrokerOrderStatus;
use App\Enums\ScoutPipelineStatus;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Models\Position;
use App\Services\SmartAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SmartBudgetAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocate_budget_bulk_action_exists_on_radar(): void
    {
        $this->authenticateFilament();

        Livewire::test(ListScouts::class)
            ->assertTableBulkActionExists('allocate_budget');
    }

    public function test_allocate_budget_applies_quantity_without_activating(): void
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
        ]);

        Livewire::test(ListScouts::class)
            ->callTableBulkAction('allocate_budget', [$a, $b], data: [
                'mode' => SmartAllocationService::MODE_SMART,
            ]);

        $a->refresh();
        $b->refresh();

        $this->assertNotSame(5, (int) $a->quantity);
        $this->assertNotNull($a->risk_budget);
        $this->assertGreaterThan((float) $b->risk_budget, (float) $a->risk_budget);
        $this->assertSame(BrokerOrderStatus::Scout, $a->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Scout, $a->scoutPipelineStatus());
        $this->assertSame(BrokerOrderStatus::Scout, $b->broker_order_status);
    }

    public function test_allocate_budget_equal_mode_splits_evenly(): void
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
        ]);

        $b = Position::factory()->for($user)->scout()->create([
            'ticker' => 'BBB',
            'last_setup_score' => 6,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
        ]);

        Livewire::test(ListScouts::class)
            ->callTableBulkAction('allocate_budget', [$a, $b], data: [
                'mode' => SmartAllocationService::MODE_EQUAL,
            ]);

        $this->assertEqualsWithDelta(
            (float) $a->fresh()->risk_budget,
            (float) $b->fresh()->risk_budget,
            0.5,
        );
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
        ]);

        $b = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EWTX',
            'last_setup_score' => 8,
            'entry_price' => 100.00,
            'latest_sma_20' => 98.00,
            'latest_atr_14' => 2.00,
            'sector_etf' => 'XLF',
            'quantity' => 5,
        ]);

        Livewire::test(ListScouts::class)
            ->callTableBulkAction('allocate_budget', [$a, $b], data: [
                'mode' => SmartAllocationService::MODE_SMART,
            ]);

        $a->refresh();
        $allocatedQty = (int) $a->quantity;

        Livewire::test(ListScouts::class)
            ->callTableAction('mark_buy_stop_placed', $a);

        $a->refresh();

        $this->assertSame($allocatedQty, (int) $a->quantity);
        $this->assertSame(BrokerOrderStatus::Pending, $a->broker_order_status);
        $this->assertSame(ScoutPipelineStatus::Active, $a->scoutPipelineStatus());
    }
}
