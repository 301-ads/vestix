<?php

namespace Tests\Unit;

use App\Enums\AlertEventType;
use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\SendAlertJob;
use App\Models\Position;
use App\Models\User;
use App\Services\StrategyAnalyticsService;
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

        (new CheckPositionAlertTriggersJob($position->id))->handle(app(\App\Alerts\AlertDispatcher::class));

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
}
