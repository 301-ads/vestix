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

    public function test_uses_revolut_workflow_from_position_broker_tag(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $position = Position::factory()->for($user)->create(['broker' => Broker::Revolut]);

        $this->assertTrue($position->usesRevolutWorkflow());
        $this->assertFalse($position->usesIbkrWorkflow());
    }

    public function test_uses_ibkr_workflow_from_position_broker_tag(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);
        $position = Position::factory()->for($user)->create(['broker' => Broker::Ibkr]);

        $this->assertTrue($position->usesIbkrWorkflow());
        $this->assertFalse($position->usesRevolutWorkflow());
    }

    public function test_suppresses_limit_sell_todo_for_ibkr_tagged_position(): void
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
        ]);

        $this->assertTrue($position->suppressesLimitSellTodo());
        $this->assertNull($position->primaryActionType());
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
        $this->assertNull($position->primaryActionType());
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
        $this->assertNull($scout->primaryActionType());
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
        ]);

        // new_sl = 60 - 0.20 = 59.80 > 59.70 → UPDATE, but suppressed during RTH
        $this->assertSame('UPDATE', $position->action_command);
        $this->assertNull($position->primaryActionType());

        Carbon::setTestNow(Carbon::parse('2026-06-15 16:20:00', 'America/New_York'));
        $this->assertSame(Position::PRIMARY_ACTION_UPDATE_SL, $position->primaryActionType());
    }
}
