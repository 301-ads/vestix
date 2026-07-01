<?php

namespace Tests\Feature\Console;

use App\Enums\BrokerOrderStatus;
use App\Models\Position;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunMarketOpenBuyStopRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_sends_reminder_immediately_and_clears_scheduled_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 15:35:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'NVDA',
            'entry_price' => 120.00,
            'quantity' => 5,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:market-open-buy-stop-reminders')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->data()['text'] ?? '', 'BUY-STOP REMINDER: NVDA'));

        $scout->refresh();

        $this->assertNull($scout->market_open_reminder_on);
    }

    public function test_command_skips_scout_without_entry_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 15:35:00', 'Europe/Amsterdam'));

        $user = User::factory()->create(['telegram_chat_id' => '12345']);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => null,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:market-open-buy-stop-reminders')
            ->assertSuccessful();

        $scout->refresh();

        $this->assertSame('2026-07-02', $scout->market_open_reminder_on?->toDateString());
    }

    public function test_command_skips_user_without_telegram(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 15:35:00', 'Europe/Amsterdam'));

        $user = User::factory()->create(['telegram_chat_id' => null]);

        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 50.00,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:market-open-buy-stop-reminders')
            ->assertSuccessful();

        $scout->refresh();

        $this->assertSame('2026-07-02', $scout->market_open_reminder_on?->toDateString());
    }

    public function test_dispatch_sends_telegram_with_broker_link(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 15:35:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create([
            'telegram_chat_id' => '12345',
            'primary_broker' => 'revolut',
        ]);
        UserAlertPreference::ensureDefaultsForUser($user);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'AAPL',
            'entry_price' => 185.50,
            'quantity' => 2,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:market-open-buy-stop-reminders')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_contains($body['text'] ?? '', 'BUY-STOP REMINDER: AAPL')
                && str_contains($body['text'] ?? '', 'app-invest/stocks/aapl');
        });

        $scout->refresh();

        $this->assertNull($scout->market_open_reminder_on);
    }
}
