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

class RunExecutionPrepDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_sends_stop_limit_plans_without_clearing_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 14:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'COO',
            'entry_price' => 71.80,
            'quantity' => 34,
            'latest_sma_20' => 69.00,
            'latest_atr_14' => 1.50,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:execution-prep-digest')
            ->assertSuccessful();

        Http::assertSent(function ($request) use ($scout): bool {
            $text = $request->data()['text'] ?? '';

            return str_contains($text, 'Daily Execution Digest')
                && str_contains($text, 'COO')
                && str_contains($text, 'STOP LIMIT')
                && str_contains($text, 'Buy-Stop: $71.80')
                && str_contains($text, 'Limit Prijs: $71.95')
                && str_contains($text, 'GTC')
                && ! str_contains($text, 'Wacht ~5 min');
        });

        $this->assertSame('2026-07-02', $scout->fresh()->market_open_reminder_on?->toDateString());
    }

    public function test_skips_non_trading_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 14:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        Position::factory()->for($user)->scout()->create([
            'entry_price' => 50,
            'market_open_reminder_on' => '2026-07-04',
        ]);

        $this->artisan('vestix:execution-prep-digest')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org'));
    }
}
