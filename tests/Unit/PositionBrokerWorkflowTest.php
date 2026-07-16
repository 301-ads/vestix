<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use App\Services\Bankroll\IbkrBankrollSource;
use App\Services\Bankroll\ManualBankrollSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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

    public function test_manual_bankroll_source_returns_configured_amount(): void
    {
        $user = User::factory()->create();
        $source = new ManualBankrollSource(12345.67);

        $this->assertSame(12345.67, $source->resolveAmount($user));
    }

    public function test_ibkr_bankroll_source_is_not_implemented(): void
    {
        $user = User::factory()->create();
        $source = new IbkrBankrollSource();

        $this->expectException(RuntimeException::class);
        $source->resolveAmount($user);
    }
}
