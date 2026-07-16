<?php

namespace Tests\Feature\Console;

use App\Contracts\QuoteProvider;
use App\Enums\BrokerOrderStatus;
use App\Enums\ExecutionDigestStatus;
use App\Models\Position;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RunExecutionOrderPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_sends_one_digest_with_safe_and_cancelled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 15:31:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchSessionQuote')
            ->with('RPRX')
            ->andReturn(['open' => 55.80, 'close' => 55.80, 'high' => 56.0, 'low' => 55.5]);
        $quotes->shouldReceive('fetchSessionQuote')
            ->with('EWTX')
            ->andReturn(['open' => 41.10, 'close' => 41.10, 'high' => 41.2, 'low' => 40.9]);
        $this->app->instance(QuoteProvider::class, $quotes);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $safe = Position::factory()->for($user)->scout()->create([
            'ticker' => 'RPRX',
            'entry_price' => 56.45,
            'quantity' => 53,
            'latest_sma_20' => 54.00,
            'latest_atr_14' => 1.20,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $cancelled = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EWTX',
            'entry_price' => 40.85,
            'quantity' => 20,
            'latest_sma_20' => 39.00,
            'latest_atr_14' => 1.00,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:execution-order-plan')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $text = $request->data()['text'] ?? '';

            return str_contains($text, 'Vestix Order Plan')
                && str_contains($text, 'RPRX')
                && str_contains($text, 'VEILIG')
                && str_contains($text, 'EWTX')
                && str_contains($text, 'GEANNULEERD');
        });

        $safe->refresh();
        $cancelled->refresh();

        $this->assertNull($safe->market_open_reminder_on);
        $this->assertNull($cancelled->market_open_reminder_on);
        $this->assertSame(ExecutionDigestStatus::Safe, $safe->execution_digest_status);
        $this->assertSame(ExecutionDigestStatus::CancelledGapUp, $cancelled->execution_digest_status);
    }

    public function test_legacy_reminder_command_delegates_to_digest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 15:31:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchSessionQuote')->andReturn([
            'open' => 55.0,
            'close' => 55.0,
            'high' => 55.0,
            'low' => 55.0,
        ]);
        $this->app->instance(QuoteProvider::class, $quotes);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'NVDA',
            'entry_price' => 120.00,
            'quantity' => 5,
            'latest_sma_20' => 100.00,
            'latest_atr_14' => 2.00,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:market-open-buy-stop-reminders')
            ->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_contains($request->data()['text'] ?? '', 'Vestix Order Plan'));

        $this->assertNull($scout->fresh()->market_open_reminder_on);
    }

    public function test_skips_non_trading_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 15:31:00', 'Europe/Amsterdam')); // Saturday

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        Position::factory()->for($user)->scout()->create([
            'entry_price' => 50,
            'market_open_reminder_on' => '2026-07-04',
        ]);

        $this->artisan('vestix:execution-order-plan')
            ->assertSuccessful();

        Http::fake();
        Http::assertNothingSent();
    }
}
