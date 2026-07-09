<?php

namespace Tests\Unit;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Jobs\CheckTarget1AlertsJob;
use App\Jobs\SendAlertJob;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckTarget1AlertsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_queues_target_1_hit_alert_when_price_crosses_target(): void
    {
        Queue::fake();

        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        $position = Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 12.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        (new CheckTarget1AlertsJob($position->id))->handle(app(\App\Alerts\AlertDispatcher::class));

        Queue::assertPushed(SendAlertJob::class, function (SendAlertJob $job) use ($position): bool {
            return $job->positionId === $position->id
                && $job->event === AlertEventType::Target1Hit;
        });
    }

    public function test_does_not_queue_sl_can_raise_when_only_price_updated(): void
    {
        Queue::fake();

        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        $position = Position::factory()->for($user)->create([
            'entry_price' => 10.00,
            'initial_sl' => 9.00,
            'current_sl' => 9.00,
            'latest_close_price' => 10.50,
            'latest_sma_20' => 9.00,
            'latest_atr_14' => 1.00,
            'quantity' => 100,
            'status' => 'open',
        ]);

        (new CheckTarget1AlertsJob($position->id))->handle(app(\App\Alerts\AlertDispatcher::class));

        Queue::assertNotPushed(SendAlertJob::class, function (SendAlertJob $job): bool {
            return $job->event === AlertEventType::SlCanRaise;
        });
    }

    public function test_skips_target_1_for_auto_runner_bypass(): void
    {
        Queue::fake();

        $user = User::factory()->create(['primary_broker' => Broker::Revolut]);

        $position = Position::factory()->for($user)->create([
            'entry_price' => 51.50,
            'initial_sl' => 48.00,
            'current_sl' => 58.14,
            'latest_close_price' => 59.86,
            'quantity' => 22,
            'status' => 'open',
        ]);

        (new CheckTarget1AlertsJob($position->id))->handle(app(\App\Alerts\AlertDispatcher::class));

        Queue::assertNotPushed(SendAlertJob::class, function (SendAlertJob $job): bool {
            return $job->event === AlertEventType::Target1Hit;
        });
    }
}
