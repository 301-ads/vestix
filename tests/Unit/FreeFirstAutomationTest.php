<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Enums\BrokerOrderStatus;
use App\Enums\ExecutionTruthState;
use App\Enums\GapHerplanAction;
use App\Models\Position;
use App\Models\User;
use App\Services\Ibkr\IbkrPositionReconciler;
use App\Services\ProtocolComplianceService;
use App\Support\Entitlements;
use App\Support\FirstRunChecklist;
use App\Support\SniperRejectReasons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeFirstAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_checklist_tracks_incomplete_steps(): void
    {
        $user = User::factory()->create([
            'default_risk_percent' => null,
            'primary_broker' => null,
            'trading_bankroll' => null,
            'telegram_chat_id' => null,
        ]);

        $status = FirstRunChecklist::status($user);

        $this->assertFalse($status['completed']);
        $this->assertSame(0, $status['done_count']);
        $this->assertTrue(FirstRunChecklist::shouldShow($user));
    }

    public function test_first_run_checklist_completes_when_ready(): void
    {
        $user = User::factory()->create([
            'default_risk_percent' => 1,
            'primary_broker' => Broker::Revolut,
            'trading_bankroll' => 10000,
            'telegram_chat_id' => '123',
        ]);

        FirstRunChecklist::markCompletedIfReady($user->fresh());

        $this->assertNotNull(data_get($user->fresh()->ui_preferences, 'first_run.completed_at'));
        $this->assertFalse(FirstRunChecklist::shouldShow($user->fresh()));
    }

    public function test_ibkr_reconciler_detects_qty_drift(): void
    {
        $user = User::factory()->create([
            'ibkr_open_positions' => [
                ['symbol' => 'AAPL', 'quantity' => 10],
            ],
            'ibkr_last_success_at' => now(),
        ]);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'AAPL',
            'status' => 'open',
            'quantity' => 8,
            'entry_price' => 100,
            'current_sl' => 95,
        ]);

        $mismatches = app(IbkrPositionReconciler::class)->mismatches($user);

        $this->assertCount(1, $mismatches);
        $this->assertSame('qty_drift', $mismatches[0]['type']);
        $this->assertStringContainsString('neem IBKR over als Flex klopt', $mismatches[0]['message']);

        app(IbkrPositionReconciler::class)->acceptQuantity($position->fresh(), 10.0);

        $fresh = $position->fresh();
        $this->assertEquals(10.0, (float) $fresh->quantity);
        $this->assertSame('broker-synced', $fresh->data_source_label);
        $this->assertSame('Revolut', $fresh->displayDataSourceLabel());
        $this->assertSame('Gesynchroniseerd · open positie', $fresh->executionTruthState()?->label());
    }

    public function test_display_data_source_label_uses_broker_short_name(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'broker' => Broker::Ibkr,
            'data_source_label' => 'broker-synced',
            'execution_truth_state' => ExecutionTruthState::SyncedOpen,
        ]);

        $this->assertSame('IBKR', $position->displayDataSourceLabel());
        $this->assertSame('Handmatig', $position->fill(['data_source_label' => 'handmatig'])->displayDataSourceLabel());
        $this->assertSame('Gepland', $position->fill(['data_source_label' => 'planned'])->displayDataSourceLabel());
    }

    public function test_execution_truth_and_gap_herplan(): void
    {
        $user = User::factory()->create();
        $scout = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'scout',
            'ticker' => 'MSFT',
        ]);

        $scout->markSubmittedAtBroker();
        $scout->refresh();

        $this->assertSame(ExecutionTruthState::SubmittedAtBroker, $scout->execution_truth_state);
        $this->assertNotNull($scout->broker_submitted_at);
        $this->assertSame('Handmatig', $scout->displayDataSourceLabel());
        $this->assertSame('Geplaatst bij broker', $scout->executionTruthState()?->label());

        $scout->applyGapHerplan(GapHerplanAction::Skip);
        $scout->refresh();

        $this->assertSame(GapHerplanAction::Skip, $scout->gap_herplan_action);
        $this->assertNotNull($scout->order_plan_excluded_on);
    }

    public function test_clone_adds_to_order_plan(): void
    {
        $owner = User::factory()->create();
        $cloner = User::factory()->create();

        $source = Position::factory()->create([
            'user_id' => $owner->id,
            'status' => 'scout',
            'ticker' => 'NVDA',
            'entry_price' => 100,
            'current_sl' => 95,
        ]);

        $clone = $source->cloneForUser($cloner, addToOrderPlan: true);

        $this->assertSame($cloner->id, $clone->user_id);
        $this->assertNotNull($clone->fresh()->market_open_reminder_on);
        $this->assertSame(BrokerOrderStatus::Scout, $clone->fresh()->broker_order_status);
        $this->assertSame(ExecutionTruthState::Planned, $clone->fresh()->execution_truth_state);
        $this->assertTrue(
            Position::orderPlanForUser((int) $cloner->id)->contains('id', $clone->id),
        );
    }

    public function test_protocol_score_persists_on_archive(): void
    {
        $user = User::factory()->create();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'ticker' => 'AMD',
            'entry_price' => 100,
            'quantity' => 10,
            'current_sl' => 95,
            'initial_sl' => 95,
            'initial_sl_placed_at' => now(),
            'trade_journal' => 'Bounce setup',
        ]);

        $position->archiveWithExitPrice(110);

        $position->refresh();
        $this->assertSame('closed', $position->status);
        $this->assertNotNull($position->protocol_score);
        $this->assertGreaterThan(0, $position->protocol_score);
    }

    public function test_sniper_reject_reasons_explain_failures(): void
    {
        $reasons = SniperRejectReasons::forInputs([
            'open' => 100,
            'close' => 99,
            'sma10' => 101,
            'sma20' => 100,
            'sma50' => 98,
            'rsi14' => 50,
        ]);

        $this->assertNotEmpty($reasons);
    }

    public function test_entitlements_allow_free_features(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Entitlements::allows($user, Entitlements::FEATURE_IBKR_RECONCILE));
        $this->assertTrue(Entitlements::allows($user, Entitlements::FEATURE_WEEKLY_EDGE_DIGEST));
    }

    public function test_accept_quantity_refuses_shrink_without_scale_out(): void
    {
        $user = User::factory()->create([
            'ibkr_open_positions' => [
                ['symbol' => 'HALO', 'quantity' => 13],
            ],
        ]);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'HALO',
            'status' => 'open',
            'quantity' => 26,
            'entry_price' => 76.06,
            'current_sl' => 70.00,
        ]);

        $mismatches = app(IbkrPositionReconciler::class)->mismatches($user);

        $this->assertCount(1, $mismatches);
        $this->assertSame('unlogged_partial_exit', $mismatches[0]['type']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scale-out');

        app(IbkrPositionReconciler::class)->acceptQuantity($position->fresh(), 13.0);
    }

    public function test_protocol_compliance_summary(): void
    {
        $user = User::factory()->create();

        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'ticker' => 'X',
            'entry_price' => 10,
            'exit_price' => 12,
            'quantity' => 1,
            'current_sl' => 9,
            'closed_at' => now(),
            'protocol_score' => 75,
            'is_legacy' => false,
        ]);

        $summary = app(ProtocolComplianceService::class)->summaryForUser($user->id);

        $this->assertSame(1, $summary['scored_trades']);
        $this->assertSame(75.0, $summary['avg_score']);
    }
}
