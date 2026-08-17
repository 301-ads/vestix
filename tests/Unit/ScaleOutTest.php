<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\SendAlertJob;
use App\Models\Position;
use App\Models\User;
use App\Services\StrategyAnalyticsService;
use App\Support\ScaleOutDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScaleOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_1_price_at_configured_risk_reward(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 46.50,
            'initial_sl' => 44.38,
            'current_sl' => 44.38,
            'target_1_rr' => 2.0,
        ]);

        $this->assertEquals(50.74, $position->target_1_price);
    }

    public function test_stored_target_1_limit_does_not_move_when_entry_fill_changes(): void
    {
        $position = Position::factory()->make([
            'entry_price' => 16.58,
            'initial_sl' => 15.99,
            'current_sl' => 16.32,
            'target_1_rr' => 2.0,
            'target_1_limit_price' => 17.70,
        ]);

        $this->assertEquals(17.70, $position->target_1_price);
        $this->assertEquals(17.76, $position->computedTarget1PriceFromRisk());
    }

    public function test_activating_scout_freezes_copied_target_1_when_fill_differs_from_buy_stop(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 16.56,
            'quantity' => 92,
            'latest_close_price' => 16.40,
            'latest_sma_20' => 16.20,
            'latest_atr_14' => 0.42,
            'target_1_rr' => 2.0,
        ]);

        $this->assertEquals(15.99, $scout->new_sl);
        $this->assertEquals(17.70, $scout->plannedBracketTarget1Price());

        $scout->activateAsPosition(16.58, 92);
        $open = $scout->fresh();

        $this->assertSame('open', $open->status);
        $this->assertEquals(16.58, (float) $open->entry_price);
        $this->assertEquals(15.99, (float) $open->initial_sl);
        $this->assertEquals(17.70, $open->target_1_price);
        $this->assertEquals(17.70, (float) $open->target_1_limit_price);
        $this->assertEquals(17.76, $open->computedTarget1PriceFromRisk());
        $this->assertEquals(17.76, $open->pendingTarget1LimitPrice());
        $this->assertTrue($open->hasPendingTarget1Raise());
        $this->assertTrue($open->needsTarget1QtyAdjust());
        $this->assertSame(Position::PRIMARY_ACTION_ADJUST_TARGET_1, $open->fresh()->primaryActionType());

        $open->applyTarget1BrokerAdjust();
        $raised = $open->fresh();
        $this->assertEquals(17.76, $raised->target_1_price);
        $this->assertNull($raised->pendingTarget1LimitPrice());
        $this->assertFalse($raised->hasPendingTarget1Raise());
        $this->assertTrue($raised->hasTarget1QtyAdjusted());
        $this->assertFalse($raised->needsTarget1BrokerAdjust());
        $this->assertNotSame(Position::PRIMARY_ACTION_ADJUST_TARGET_1, $raised->primaryActionType());
    }

    public function test_activating_with_better_fill_does_not_queue_target_1_raise(): void
    {
        $scout = Position::factory()->scout()->create([
            'entry_price' => 16.56,
            'quantity' => 92,
            'latest_sma_20' => 16.20,
            'latest_atr_14' => 0.42,
            'target_1_rr' => 2.0,
        ]);

        $scout->activateAsPosition(16.54, 92);
        $open = $scout->fresh();

        $this->assertEquals(17.70, $open->target_1_price);
        $this->assertNull($open->pendingTarget1LimitPrice());
        $this->assertFalse($open->hasPendingTarget1Raise());
        $this->assertFalse($open->needsTarget1QtyAdjust());
    }

    public function test_ibkr_activation_queues_take_profit_qty_adjust_even_with_better_fill(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 16.56,
            'quantity' => 92,
            'latest_close_price' => 16.40,
            'latest_sma_20' => 16.20,
            'latest_atr_14' => 0.42,
            'target_1_rr' => 2.0,
            'broker' => Broker::Ibkr,
        ]);

        $scout->activateAsPosition(16.54, 92);
        $open = $scout->fresh();

        $this->assertEquals(17.70, $open->target_1_price);
        $this->assertNull($open->pendingTarget1LimitPrice());
        $this->assertTrue($open->needsTarget1QtyAdjust());
        $this->assertSame(46.0, $open->target_1_quantity);
        $this->assertSame(Position::PRIMARY_ACTION_ADJUST_TARGET_1, $open->primaryActionType());
    }

    public function test_revolut_activation_does_not_queue_take_profit_qty_adjust(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 16.56,
            'quantity' => 92,
            'latest_close_price' => 16.40,
            'latest_sma_20' => 16.20,
            'latest_atr_14' => 0.42,
            'target_1_rr' => 2.0,
        ]);

        $scout->activateAsPosition(16.58, 92);
        $open = $scout->fresh();

        $this->assertTrue($open->hasPendingTarget1Raise());
        $this->assertFalse($open->needsTarget1QtyAdjust());
        $this->assertSame(Position::PRIMARY_ACTION_PLACE_INITIAL_SL, $open->primaryActionType());
    }

    public function test_marking_bracket_placed_freezes_target_1_against_later_entry_drift(): void
    {
        $scout = Position::factory()->scout()->create([
            'entry_price' => 16.56,
            'quantity' => 92,
            'latest_sma_20' => 16.20,
            'latest_atr_14' => 0.42,
            'target_1_rr' => 2.0,
        ]);

        $scout->markSubmittedAtBroker();
        $scout->update(['entry_price' => 16.58]);

        $this->assertEquals(17.70, $scout->fresh()->target_1_price);
        $this->assertEquals(17.70, (float) $scout->fresh()->target_1_limit_price);
    }

    public function test_target_1_quantity_uses_whole_shares_rounded_to_nearest(): void
    {
        $odd = Position::factory()->make([
            'quantity' => 11,
            'first_tranche_fraction' => 0.5,
        ]);
        $even = Position::factory()->make([
            'quantity' => 10,
            'first_tranche_fraction' => 0.5,
        ]);
        $single = Position::factory()->make([
            'quantity' => 1,
            'first_tranche_fraction' => 0.5,
        ]);

        // 11 × 50% = 5.5 → 6 (nearest); always keep ≥1 runner
        $this->assertSame(6.0, $odd->target_1_quantity);
        $this->assertSame(5.0, $even->target_1_quantity);
        $this->assertNull($single->target_1_quantity);
        $this->assertSame(6.0, Position::wholeShareTrancheQuantity(11, 0.5));
        $this->assertSame(5.0, Position::wholeShareTrancheQuantity(10, 0.5));
    }

    public function test_is_target_1_hit_when_close_at_or_above_target(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'quantity' => 100,
        ]);

        $this->assertTrue($position->isTarget1Hit());
    }

    public function test_blended_unrealized_pnl_after_scale_out_and_runner_gain(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 10.00,
            'quantity' => 100,
            'initial_sl' => 9.00,
            'current_sl' => 10.00,
            'latest_close_price' => 15.00,
            'scaled_out_price' => 12.00,
            'scaled_out_quantity' => 50,
            'scaled_out_at' => now(),
            'realized_pnl' => 100.00,
        ]);

        $this->assertEquals(50.0, $position->remaining_quantity);
        $this->assertEquals(750.0, $position->current_value);
        $this->assertEquals(350.0, $position->unrealized_pnl);
        $this->assertEquals(35.0, $position->unrealized_pnl_percentage);
    }

    public function test_blended_unrealized_pnl_without_scale_out_matches_legacy_formula(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 10.00,
            'quantity' => 100,
            'latest_close_price' => 15.00,
        ]);

        $this->assertEquals(500.0, $position->unrealized_pnl);
    }

    public function test_scale_out_moves_stop_to_breakeven_and_stamps_freeride(): void
    {
        $user = User::factory()->create();

        $position = Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'quantity' => 100,
            'initial_sl' => 9.00,
            'current_sl' => 9.50,
            'latest_close_price' => 12.00,
        ]);

        $position->scaleOut(12.00, 50);

        $position->refresh();

        $this->assertTrue($position->hasScaledOut());
        $this->assertEquals(100.00, (float) $position->realized_pnl);
        $this->assertEquals(10.00, (float) $position->current_sl);
        $this->assertNotNull($position->freeride_secured_at);
        $this->assertEquals(0.0, $position->capital_risk_dollars);
    }

    public function test_archive_stores_blended_risk_reward_ratio(): void
    {
        $position = Position::factory()->create([
            'entry_price' => 10.00,
            'quantity' => 100,
            'initial_sl' => 9.00,
            'current_sl' => 10.00,
            'scaled_out_price' => 12.00,
            'scaled_out_quantity' => 50,
            'scaled_out_at' => now(),
            'realized_pnl' => 100.00,
        ]);

        $position->archiveWithExitPrice(15.00);

        $position->refresh();

        $this->assertEquals('closed', $position->status);
        $this->assertEquals(3.5, (float) $position->risk_reward_ratio);
    }

    public function test_requiring_action_includes_target_1_hit_positions(): void
    {
        $user = User::factory()->create();

        $targetHit = Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'latest_sma_20' => 11.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
        ]);

        Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.50,
            'latest_sma_20' => 11.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
        ]);

        $ids = Position::requiringActionForUser($user->id)->pluck('id');

        $this->assertTrue($ids->contains($targetHit->id));
    }

    public function test_mark_target_1_limit_placed_removes_target_1_from_requiring_action(): void
    {
        $user = User::factory()->create();

        $targetHit = Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
        ]);

        $this->assertSame(Position::PRIMARY_ACTION_TARGET_1, $targetHit->primaryActionType());
        $this->assertTrue(
            Position::requiringActionForUser($user->id)->pluck('id')->contains($targetHit->id),
        );

        $targetHit->markTarget1LimitPlaced();
        $targetHit->refresh();

        $this->assertNotSame(Position::PRIMARY_ACTION_TARGET_1, $targetHit->primaryActionType());
        $this->assertFalse(
            Position::requiringActionForUser($user->id)->pluck('id')->contains($targetHit->id),
        );
    }

    public function test_target_1_hit_alert_is_queued(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $position = Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'quantity' => 100,
        ]);

        (new CheckPositionAlertTriggersJob($position->id))->handle(app(AlertDispatcher::class));

        Queue::assertPushed(SendAlertJob::class, function (SendAlertJob $job) use ($position): bool {
            return $job->positionId === $position->id
                && $job->event === AlertEventType::Target1Hit;
        });
    }

    public function test_runner_performance_coach_metric(): void
    {
        $user = User::factory()->create();
        $analytics = app(StrategyAnalyticsService::class);

        Position::factory()->for($user)->closed()->create([
            'entry_price' => 10.00,
            'quantity' => 100,
            'initial_sl' => 9.00,
            'current_sl' => 10.00,
            'exit_price' => 15.00,
            'scaled_out_price' => 12.00,
            'scaled_out_quantity' => 50,
            'scaled_out_at' => now()->subDay(),
            'realized_pnl' => 100.00,
            'risk_reward_ratio' => 3.5,
            'target_1_rr' => 2.0,
        ]);

        $runner = $analytics->runnerPerformance($user->id);

        $this->assertSame(1, $runner['scaled_out_trades']);
        $this->assertSame(100.0, $runner['runner_beat_target_rate']);
        $this->assertSame(1.5, $runner['avg_runner_uplift_r']);
    }

    public function test_is_auto_runner_bypass_when_stop_at_or_above_entry_without_scale_out(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 51.50,
            'current_sl' => 58.14,
            'quantity' => 22,
        ]);

        $this->assertTrue($position->isAutoRunnerBypass());
    }

    public function test_is_auto_runner_bypass_true_at_exact_breakeven(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 51.50,
            'current_sl' => 51.50,
            'quantity' => 22,
        ]);

        $this->assertTrue($position->isAutoRunnerBypass());
    }

    public function test_is_auto_runner_bypass_false_when_stop_below_entry(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 51.50,
            'current_sl' => 49.00,
            'quantity' => 22,
        ]);

        $this->assertFalse($position->isAutoRunnerBypass());
    }

    public function test_is_auto_runner_bypass_false_after_scale_out(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 51.50,
            'current_sl' => 58.14,
            'scaled_out_price' => 59.00,
            'scaled_out_quantity' => 11,
            'scaled_out_at' => now(),
            'quantity' => 22,
        ]);

        $this->assertFalse($position->isAutoRunnerBypass());
    }

    public function test_order_plan_shows_skipped_target_1_for_auto_runner_bypass(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'quantity' => 22,
        ]);

        $html = ScaleOutDisplay::orderPlanHtml($position)->toHtml();

        $this->assertStringContainsString('Target 1 overgeslagen of nog niet gelogd (Breakeven bereikt)', $html);
        $this->assertStringContainsString('Log Scale-out alsnog', $html);
        $this->assertStringContainsString('vestix-order-plan__step-one-action', $html);
        $this->assertStringContainsString('vestix-order-plan__step--bypass', $html);
        $this->assertStringContainsString('border-gray-300', $html);
        $this->assertStringContainsString('bg-primary-500', $html);
        $this->assertStringNotContainsString('Limit sell', $html);
        $this->assertStringNotContainsString('>1<', $html);
    }

    public function test_scale_out_keeps_stop_above_entry_when_already_freeride(): void
    {
        $user = User::factory()->create();

        $position = Position::factory()->for($user)->create([
            'entry_price' => 245.38,
            'quantity' => 3,
            'initial_sl' => 240.00,
            'current_sl' => 245.67,
            'latest_close_price' => 261.31,
            'status' => 'open',
        ]);

        $this->assertTrue($position->isAutoRunnerBypass());

        $position->scaleOut(260.00, 1);

        $position->refresh();

        $this->assertTrue($position->hasScaledOut());
        $this->assertFalse($position->isAutoRunnerBypass());
        $this->assertEquals(245.67, (float) $position->current_sl);
        $this->assertEquals(14.62, (float) $position->realized_pnl);
    }

    public function test_can_log_scale_out_under_bypass_even_when_target_1_not_hit(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 245.38,
            'initial_sl' => 237.33,
            'current_sl' => 245.67,
            'latest_close_price' => 261.27,
            'quantity' => 3,
        ]);

        $this->assertTrue($position->isAutoRunnerBypass());
        $this->assertNotNull($position->target_1_price);
        $this->assertTrue((float) $position->latest_close_price < (float) $position->target_1_price);
        $this->assertFalse($position->isTarget1Hit());
        $this->assertTrue($position->canLogScaleOut());
    }

    public function test_order_plan_hunt_phase_shows_active_step_one_number(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::None]);

        $position = Position::factory()->for($user)->make([
            'status' => 'open',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.50,
            'quantity' => 100,
        ]);

        $html = ScaleOutDisplay::orderPlanHtml($position)->toHtml();

        $this->assertStringContainsString('vestix-order-plan__step--active', $html);
        $this->assertStringContainsString('vestix-order-plan__step--pending', $html);
        $this->assertStringContainsString('bg-primary-500', $html);
        $this->assertStringContainsString('Target 1 &middot; Verkoop 50%', $html);
        $this->assertStringContainsString('Limit sell', $html);
    }

    public function test_order_plan_warns_ibkr_to_cut_take_profit_quantity_after_fill(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);

        $position = Position::factory()->for($user)->make([
            'status' => 'open',
            'broker' => Broker::Ibkr,
            'entry_price' => 28.07,
            'initial_sl' => 26.80,
            'current_sl' => 26.80,
            'latest_close_price' => 29.00,
            'quantity' => 41,
        ]);

        $html = ScaleOutDisplay::orderPlanHtml($position)->toHtml();

        $this->assertStringContainsString('Limit sell', $html);
        $this->assertStringContainsString('Wijzig Take Profit naar', $html);
        $this->assertStringContainsString('21 stuks (50%)', $html);
        $this->assertStringContainsString('TradingView plaatst TP op 100%', $html);
    }

    public function test_order_plan_warns_ibkr_to_replace_stop_after_target_1_fill(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Ibkr]);

        $position = Position::factory()->for($user)->make([
            'status' => 'open',
            'broker' => Broker::Ibkr,
            'entry_price' => 16.58,
            'initial_sl' => 15.99,
            'current_sl' => 15.99,
            'latest_close_price' => 17.80,
            'quantity' => 92,
            'target_1_qty_adjusted_at' => now(),
        ]);

        $html = ScaleOutDisplay::orderPlanHtml($position)->toHtml();

        $this->assertTrue($position->isTarget1Hit());
        $this->assertStringContainsString('Plaats een nieuwe stop op $16.58', $html);
        $this->assertStringContainsString('46 stuks', $html);
        $this->assertStringContainsString('IBKR annuleerde de bracket-SL', $html);
    }

    public function test_order_plan_hunt_phase_shows_revolut_monitoring_copy(): void
    {
        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        $position = Position::factory()->for($user)->make([
            'status' => 'open',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.50,
            'quantity' => 100,
        ]);

        $html = ScaleOutDisplay::orderPlanHtml($position)->toHtml();

        $this->assertStringContainsString('Vestix monitort Target 1', $html);
        $this->assertStringContainsString('100% stop-loss actief bij Revolut', $html);
        $this->assertStringNotContainsString('Limit sell', $html);
    }

    public function test_order_plan_shows_green_checkmark_after_scale_out(): void
    {
        $position = Position::factory()->make([
            'status' => 'open',
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 10.00,
            'quantity' => 100,
            'scaled_out_price' => 12.00,
            'scaled_out_quantity' => 50,
            'scaled_out_at' => now(),
            'realized_pnl' => 100.00,
        ]);

        $html = ScaleOutDisplay::orderPlanHtml($position)->toHtml();

        $this->assertStringContainsString('vestix-order-plan__step--completed', $html);
        $this->assertStringContainsString('bg-success-500', $html);
        $this->assertStringContainsString('50 stuks verkocht op $12.00', $html);
        $this->assertStringContainsString('+$100.00 winst veiliggesteld', $html);
        $this->assertStringContainsString('Target 1 &middot; Verkoop 50%', $html);
        $this->assertStringContainsString('Open positie', $html);
        $this->assertStringContainsString('$500.00 (50 stuks)', $html);
        $this->assertStringNotContainsString('100 stuks)', $html);
    }

    public function test_primary_action_type_excludes_target_1_for_auto_runner_bypass(): void
    {
        $user = User::factory()->create();

        $position = Position::factory()->for($user)->create([
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'latest_sma_20' => 57.00,
            'latest_atr_14' => 1.50,
            'quantity' => 22,
            'status' => 'open',
        ]);

        $this->assertTrue($position->isTarget1Hit());
        $this->assertTrue($position->isAutoRunnerBypass());
        $this->assertNotSame(Position::PRIMARY_ACTION_TARGET_1, $position->primaryActionType());
    }

    public function test_requiring_action_excludes_auto_runner_bypass_target_1_position(): void
    {
        $user = User::factory()->create();

        $bypassPosition = Position::factory()->for($user)->create([
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'latest_sma_20' => 57.00,
            'latest_atr_14' => 1.50,
            'quantity' => 22,
            'status' => 'open',
        ]);

        $ids = Position::requiringActionForUser($user->id)->pluck('id');

        $this->assertFalse($ids->contains($bypassPosition->id));
    }

    public function test_target_1_hit_alert_not_queued_for_auto_runner_bypass(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $position = Position::factory()->for($user)->create([
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'quantity' => 22,
            'status' => 'open',
        ]);

        (new CheckPositionAlertTriggersJob($position->id))->handle(app(AlertDispatcher::class));

        Queue::assertNotPushed(SendAlertJob::class, function (SendAlertJob $job) use ($position): bool {
            return $job->positionId === $position->id
                && $job->event === AlertEventType::Target1Hit;
        });
    }

    public function test_scale_out_rejects_selling_entire_position(): void
    {
        $position = Position::factory()->create([
            'status' => 'open',
            'entry_price' => 76.06,
            'quantity' => 13,
            'initial_sl' => 70.00,
            'current_sl' => 70.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runner');

        $position->scaleOut(80.72, 13);
    }

    public function test_collapsed_halo_style_quantity_blends_tp_and_runner_pnl(): void
    {
        // quantity wrongly shrunk to scale-out size (IBKR accept before logging TP)
        $position = Position::factory()->make([
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 76.06,
            'quantity' => 13,
            'exit_price' => 83.18,
            'scaled_out_price' => 80.72,
            'scaled_out_quantity' => 13,
            'scaled_out_at' => now(),
            'realized_pnl' => 60.58,
            'first_tranche_fraction' => 0.5,
        ]);

        $this->assertSame(26.0, $position->quantityForPnl());
        $this->assertSame(13.0, $position->remaining_quantity);
        // TP $60.58 + runner (83.18 − 76.06) × 13 = 60.58 + 92.56
        $this->assertEqualsWithDelta(153.14, $position->unrealized_pnl, 0.01);
        $this->assertEqualsWithDelta(7.74, $position->unrealized_pnl_percentage, 0.01);
    }

    public function test_archive_repairs_collapsed_quantity_before_close(): void
    {
        $position = Position::factory()->create([
            'status' => 'open',
            'entry_price' => 76.06,
            'quantity' => 13,
            'initial_sl' => 70.00,
            'current_sl' => 76.06,
            'scaled_out_price' => 80.72,
            'scaled_out_quantity' => 13,
            'scaled_out_at' => now(),
            'realized_pnl' => 60.58,
            'first_tranche_fraction' => 0.5,
        ]);

        $position->archiveWithExitPrice(83.18);
        $position->refresh();

        $this->assertEquals(26.0, (float) $position->quantity);
        $this->assertEquals('closed', $position->status);
        $this->assertEqualsWithDelta(153.14, $position->unrealized_pnl, 0.01);
    }
}
