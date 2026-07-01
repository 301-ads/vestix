<?php

namespace Tests\Unit;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_premarket_gap_alert_can_be_sent_again_on_a_new_day(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'status' => 'scout',
            'ticker' => 'ON',
            'entry_price' => 50,
            'signal_high' => 49,
        ]);

        $dispatcher = app(AlertDispatcher::class);

        Carbon::setTestNow('2026-06-15 15:00:00');

        $this->assertTrue($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::PremarketGapRisk,
            ['premarket_price' => 55, 'bounce_high' => 49, 'gap_pct' => 10],
        ));

        $this->assertFalse($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::PremarketGapRisk,
            ['premarket_price' => 55, 'bounce_high' => 49, 'gap_pct' => 10],
        ));

        Carbon::setTestNow('2026-06-16 15:00:00');

        $this->assertTrue($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::PremarketGapRisk,
            ['premarket_price' => 56, 'bounce_high' => 49, 'gap_pct' => 12],
        ));

        $this->assertEquals(1, PositionAlert::query()->count());
        $this->assertSame('2026-06-16', PositionAlert::query()->first()?->sent_at?->toDateString());
    }

    public function test_market_open_reminder_is_not_sent_twice_for_same_reminder_date(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $position = Position::factory()->scout()->create([
            'user_id' => $user->id,
            'ticker' => 'AAPL',
            'entry_price' => 100,
            'quantity' => 2,
        ]);

        $dispatcher = app(AlertDispatcher::class);
        $context = ['reminder_date' => '2026-07-02', 'user' => $user];

        $this->assertTrue($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::MarketOpenBuyStopReminder,
            $context,
        ));

        $this->assertFalse($dispatcher->dispatchNow(
            $user->id,
            $position->id,
            AlertEventType::MarketOpenBuyStopReminder,
            $context,
        ));

        $this->assertEquals(1, PositionAlert::query()->count());
    }
}
