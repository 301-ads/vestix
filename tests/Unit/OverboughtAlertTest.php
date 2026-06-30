<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertChannelType;
use App\Enums\AlertEventType;
use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\SendAlertJob;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OverboughtAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_overbought_alert_is_queued_when_rsi_is_high(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'scout_rsi' => 72.00,
            'latest_close_price' => 50.00,
            'latest_sma_20' => 48.00,
            'latest_atr_14' => 2.00,
            'current_sl' => 40.00,
        ]);

        (new CheckPositionAlertTriggersJob($position->id))->handle(app(AlertDispatcher::class));

        Queue::assertPushed(SendAlertJob::class, function (SendAlertJob $job) use ($position): bool {
            return $job->positionId === $position->id
                && $job->event === AlertEventType::Overbought;
        });
    }

    public function test_overbought_alert_is_not_sent_twice_for_same_episode(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'ticker' => 'BAC',
            'scout_rsi' => 71.00,
            'latest_close_price' => 57.88,
            'latest_sma_20' => 55.53,
            'latest_atr_14' => 1.20,
            'current_sl' => 54.96,
        ]);

        $dispatcher = app(AlertDispatcher::class);

        $this->assertTrue($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::Overbought,
            ['rsi' => 71.00],
        ));

        $this->assertFalse($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::Overbought,
            ['rsi' => 71.00],
        ));

        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::Overbought)->count());
    }

    public function test_overbought_episode_resets_when_rsi_drops(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'scout_rsi' => 71.00,
            'latest_close_price' => 57.88,
            'latest_sma_20' => 55.53,
            'latest_atr_14' => 1.20,
            'current_sl' => 54.96,
        ]);

        PositionAlert::query()->create([
            'user_id' => $user->id,
            'position_id' => $position->id,
            'event_type' => AlertEventType::Overbought,
            'channel_type' => AlertChannelType::Telegram,
            'payload' => ['rsi' => 71.00],
            'sent_at' => now(),
        ]);

        $position->update(['scout_rsi' => 65.00]);

        (new CheckPositionAlertTriggersJob($position->id))->handle(app(AlertDispatcher::class));

        $this->assertEquals(0, PositionAlert::query()->where('event_type', AlertEventType::Overbought)->count());
    }
}
