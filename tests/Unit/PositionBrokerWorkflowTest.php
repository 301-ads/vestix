<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use App\Services\Bankroll\IbkrBankrollSource;
use App\Services\Bankroll\ManualBankrollSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PositionBrokerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_ibkr_workflow_for_all_positions(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $position = Position::factory()->for($user)->create(['broker' => Broker::Ibkr]);

        $this->assertTrue($position->usesIbkrWorkflow());
    }

    public function test_effective_broker_falls_back_to_ibkr(): void
    {
        $user = User::factory()->create(['primary_broker' => null]);
        $position = Position::factory()->for($user)->scout()->create(['broker' => null]);

        $this->assertSame(Broker::Ibkr, $position->effectiveBroker());
    }

    public function test_suppresses_limit_sell_todo_for_ibkr_workflow(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'broker' => Broker::Ibkr,
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.50,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $this->assertTrue($position->suppressesLimitSellTodo());
        $this->assertSame(Position::PRIMARY_ACTION_ADJUST_TARGET_1, $position->primaryActionType());
        $this->assertTrue($position->needsTarget1QtyAdjust());
    }

    public function test_ibkr_target_1_fill_queues_runner_stop_loss_replace(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'broker' => Broker::Ibkr,
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
            'target_1_qty_adjusted_at' => now(),
        ]);

        $this->assertTrue($position->isTarget1Hit());
        $this->assertTrue($position->needsRunnerSlReplace());
        $this->assertSame(50.0, $position->runnerQuantity());
        $this->assertEquals(10.00, $position->runnerStopLossPrice());
        $this->assertSame(Position::PRIMARY_ACTION_PLACE_RUNNER_SL, $position->primaryActionType());
        $this->assertFalse($position->needsTarget1QtyAdjust());

        $position->applyRunnerSlPlaced();
        $fresh = $position->fresh();

        $this->assertTrue($fresh->hasRunnerSlPlaced());
        $this->assertEquals(10.00, (float) $fresh->current_sl);
        $this->assertNotNull($fresh->freeride_secured_at);
        $this->assertFalse($fresh->needsRunnerSlReplace());
        $this->assertNotSame(Position::PRIMARY_ACTION_PLACE_RUNNER_SL, $fresh->primaryActionType());
    }

    public function test_ibkr_scale_out_queues_runner_stop_loss_even_if_price_has_not_synced(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'broker' => Broker::Ibkr,
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 11.50,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        $this->assertFalse($position->isTarget1Hit());
        $position->scaleOut(12.00, 50);

        $fresh = $position->fresh();
        $this->assertTrue($fresh->needsRunnerSlReplace());
        $this->assertSame(50.0, $fresh->runnerQuantity());
        $this->assertSame(Position::PRIMARY_ACTION_PLACE_RUNNER_SL, $fresh->primaryActionType());
    }

    public function test_suppresses_initial_sl_todo_for_ibkr_bracket_workflow(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->for($user)->awaitingInitialSlPlacement()->create([
            'broker' => Broker::Ibkr,
            'entry_price' => 79.50,
            'initial_sl' => 76.10,
            'current_sl' => 76.10,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'quantity' => 12,
            'status' => 'open',
        ]);

        $this->assertTrue($position->suppressesInitialSlTodo());
        $this->assertSame(Position::PRIMARY_ACTION_ADJUST_TARGET_1, $position->primaryActionType());
    }

    public function test_activate_as_position_marks_initial_sl_placed_for_ibkr(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'ALL',
            'entry_price' => 245.00,
            'latest_close_price' => 245.00,
            'latest_sma_20' => 240.00,
            'latest_atr_14' => 4.00,
            'broker' => Broker::Ibkr,
        ]);

        $scout->activateAsPosition(245.40, 5);
        $scout->refresh();

        $this->assertSame('open', $scout->status);
        $this->assertSame(Broker::Ibkr, $scout->broker);
        $this->assertNotNull($scout->initial_sl_placed_at);
        $this->assertSame(Position::PRIMARY_ACTION_ADJUST_TARGET_1, $scout->primaryActionType());
        $this->assertTrue($scout->hasPendingTarget1Raise());
        $this->assertTrue($scout->needsTarget1QtyAdjust());
    }

    public function test_manual_bankroll_source_returns_configured_amount(): void
    {
        $user = User::factory()->create();
        $source = new ManualBankrollSource(12345.67);

        $this->assertSame(12345.67, $source->resolveAmount($user));
    }

    public function test_ibkr_bankroll_source_resolves_via_stub_reader(): void
    {
        $user = User::factory()->create(['baseline_capital' => 3428.40]);
        $source = app(IbkrBankrollSource::class);

        $this->assertSame(3428.40, $source->resolveAmount($user));
    }

    public function test_update_sl_action_is_hidden_during_us_regular_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:08:00', 'America/New_York'));

        $user = User::factory()->create();
        $position = Position::factory()->for($user)->create([
            'broker' => Broker::Ibkr,
            'entry_price' => 59.00,
            'initial_sl' => 59.70,
            'current_sl' => 59.70,
            'latest_close_price' => 62.00,
            'latest_sma_20' => 60.00,
            'latest_atr_14' => 0.40,
            'quantity' => 100,
            'status' => 'open',
            'initial_sl_placed_at' => now(),
            'target_1_qty_adjusted_at' => now(),
        ]);

        // new_sl = 60 - 0.20 = 59.80 > 59.70 → UPDATE, but suppressed during RTH
        $this->assertSame('UPDATE', $position->action_command);
        $this->assertNull($position->primaryActionType());

        Carbon::setTestNow(Carbon::parse('2026-06-15 16:20:00', 'America/New_York'));
        $this->assertSame(Position::PRIMARY_ACTION_UPDATE_SL, $position->primaryActionType());
    }
}
