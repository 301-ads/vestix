<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_is_not_sent_twice_for_same_event(): void
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
            'ticker' => 'AAPL',
            'entry_price' => 100,
            'current_sl' => 95,
            'latest_close_price' => 110,
            'latest_sma_20' => 108,
            'latest_atr_14' => 4,
            'quantity' => 10,
        ]);

        $dispatcher = app(AlertDispatcher::class);

        $this->assertTrue($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::SlCanRaise,
        ));

        $this->assertFalse($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::SlCanRaise,
        ));

        $this->assertEquals(1, PositionAlert::query()->count());
    }

    public function test_respects_disabled_event_preference(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);
        $user->alertPreferences()->first()?->update([
            'active_events' => [AlertEventType::FreerideSecured->value],
        ]);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'open',
            'ticker' => 'AAPL',
            'entry_price' => 100,
            'current_sl' => 95,
            'latest_close_price' => 110,
            'latest_sma_20' => 108,
            'latest_atr_14' => 4,
            'quantity' => 10,
        ]);

        $dispatcher = app(AlertDispatcher::class);

        $this->assertFalse($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::SlCanRaise,
        ));

        $this->assertEquals(0, PositionAlert::query()->count());
    }
}
