<?php

namespace Tests\Feature\Console;

use App\Contracts\QuoteProvider;
use App\Enums\AlertEventType;
use App\Enums\BrokerOrderStatus;
use App\Enums\ExecutionDigestStatus;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RunOrderPlanPremarketPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prunes_gap_down_below_sma_and_notifies(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 14:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')
            ->with('EMBJ', Mockery::any())
            ->andReturn(62.10);
        $quotes->shouldReceive('fetchPremarketPrice')
            ->with('KVUE', Mockery::any())
            ->andReturn(21.50);
        $this->app->instance(QuoteProvider::class, $quotes);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $embj = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EMBJ',
            'entry_price' => 64.50,
            'quantity' => 10,
            'latest_sma_20' => 63.61,
            'latest_close_price' => 64.37,
            'latest_atr_14' => 1.20,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $kvue = Position::factory()->for($user)->scout()->create([
            'ticker' => 'KVUE',
            'entry_price' => 21.80,
            'quantity' => 20,
            'latest_sma_20' => 20.50,
            'latest_close_price' => 21.40,
            'latest_atr_14' => 0.40,
            'broker_order_status' => BrokerOrderStatus::Scout,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:order-plan-premarket-prune')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $text = $request->data()['text'] ?? '';

            return str_contains($text, 'Order Plan herzien')
                && str_contains($text, 'EMBJ')
                && str_contains($text, 'SMA 20')
                && str_contains($text, 'Herverdeel')
                && str_contains($text, 'Toepassen')
                && ! str_contains($text, 'KVUE');
        });

        $embj->refresh();
        $kvue->refresh();

        $this->assertNull($embj->market_open_reminder_on);
        $this->assertSame('2026-07-02', $kvue->market_open_reminder_on?->toDateString());
        $this->assertSame(ExecutionDigestStatus::CancelledTrendBreak, $embj->execution_digest_status);
        $this->assertEqualsWithDelta(62.10, (float) $embj->execution_digest_price, 0.001);
        $this->assertEquals(
            1,
            PositionAlert::query()->where('event_type', AlertEventType::OrderPlanRevised)->count(),
        );
    }

    public function test_prep_digest_excludes_pruned_gap_down(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 14:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')
            ->with('EMBJ', Mockery::any())
            ->andReturn(62.10);
        $quotes->shouldReceive('fetchPremarketPrice')
            ->with('ALL', Mockery::any())
            ->andReturn(195.00);
        $this->app->instance(QuoteProvider::class, $quotes);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'EMBJ',
            'entry_price' => 64.50,
            'quantity' => 10,
            'latest_sma_20' => 63.61,
            'latest_close_price' => 64.37,
            'latest_atr_14' => 1.20,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        Position::factory()->for($user)->scout()->create([
            'ticker' => 'ALL',
            'entry_price' => 196.00,
            'quantity' => 5,
            'latest_sma_20' => 190.00,
            'latest_close_price' => 194.00,
            'latest_atr_14' => 2.00,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:order-plan-premarket-prune')->assertSuccessful();
        $this->artisan('vestix:execution-prep-digest')->assertSuccessful();

        $digestMessages = collect(Http::recorded())
            ->map(fn (array $pair): string => $pair[0]->data()['text'] ?? '')
            ->filter(fn (string $text): bool => str_contains($text, 'Daily Execution Digest'));

        $this->assertCount(1, $digestMessages);
        $digest = $digestMessages->first();
        $this->assertStringContainsString('ALL', $digest);
        $this->assertStringNotContainsString('EMBJ', $digest);
    }

    public function test_keeps_scout_when_premarket_above_sma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 14:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $quotes = Mockery::mock(QuoteProvider::class);
        $quotes->shouldReceive('fetchPremarketPrice')->andReturn(65.00);
        $this->app->instance(QuoteProvider::class, $quotes);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $scout = Position::factory()->for($user)->scout()->create([
            'ticker' => 'EMBJ',
            'entry_price' => 64.50,
            'latest_sma_20' => 63.61,
            'latest_close_price' => 64.37,
            'market_open_reminder_on' => '2026-07-02',
        ]);

        $this->artisan('vestix:order-plan-premarket-prune')->assertSuccessful();

        Http::assertNotSent(fn ($request): bool => str_contains(
            $request->data()['text'] ?? '',
            'Order Plan herzien',
        ));

        $this->assertSame('2026-07-02', $scout->fresh()->market_open_reminder_on?->toDateString());
    }

    public function test_skips_non_trading_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 14:30:00', 'Europe/Amsterdam'));

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        $scout = Position::factory()->for($user)->scout()->create([
            'entry_price' => 50,
            'latest_sma_20' => 55,
            'market_open_reminder_on' => '2026-07-04',
        ]);

        $this->artisan('vestix:order-plan-premarket-prune')->assertSuccessful();

        $this->assertSame('2026-07-04', $scout->fresh()->market_open_reminder_on?->toDateString());
    }
}
