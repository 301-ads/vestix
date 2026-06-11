<?php

namespace Tests\Feature\Console;

use App\Console\Commands\WatchScouts;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchScoutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'swng.telegram.bot_token' => 'test-token',
            'swng.telegram.chat_id' => '123456',
            'swng.polygon.api_key' => 'test-polygon-key',
            'swng.polygon.base_url' => 'https://api.polygon.io',
            'swng.scout_watcher.entry_proximity_percent' => 0.5,
            'swng.scout_watcher.min_score_points' => 6,
            'swng.scout_watcher.alert_cooldown_hours' => 24,
            'swng.scout_watcher.quotes_per_minute' => 4,
            'swng.scout_watcher.chunk_pause_seconds' => 0,
        ]);

        Cache::lock(WatchScouts::LOCK_KEY)->forceRelease();
    }

    public function test_command_skips_when_telegram_not_configured(): void
    {
        config(['swng.telegram.bot_token' => null]);

        $this->artisan('swng:watch-scouts')
            ->expectsOutput('Telegram of koers-API niet geconfigureerd — watcher overgeslagen.')
            ->assertSuccessful();
    }

    public function test_command_runs_with_alpha_vantage_when_polygon_missing(): void
    {
        config([
            'swng.polygon.api_key' => null,
            'swng.alpha_vantage.api_key' => 'test-alpha-key',
            'swng.alpha_vantage.base_url' => 'https://www.alphavantage.co/query',
        ]);

        $position = $this->createQualifyingScout('PANW', 100.50);

        Http::fake([
            'www.alphavantage.co/*' => Http::response([
                'Global Quote' => ['05. price' => '100.00'],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('swng:watch-scouts')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));

        $position->refresh();
        $this->assertNotNull($position->telegram_alert_sent_at);
    }

    public function test_command_sends_telegram_alert_for_qualifying_scout(): void
    {
        $position = $this->createQualifyingScout('PANW', 100.50);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => ['p' => 100.00],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('swng:watch-scouts')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.polygon.io'));

        $position->refresh();
        $this->assertNotNull($position->telegram_alert_sent_at);
    }

    public function test_command_does_not_alert_when_price_outside_entry_margin(): void
    {
        $position = $this->createQualifyingScout('PANW', 100.50);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => ['p' => 90.00],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('swng:watch-scouts')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.polygon.io'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));

        $position->refresh();
        $this->assertNull($position->telegram_alert_sent_at);
    }

    public function test_command_skips_scout_with_recent_alert(): void
    {
        Position::factory()->create([
            'ticker' => 'PANW',
            'status' => 'scout',
            'entry_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'telegram_alert_sent_at' => now()->subHour(),
        ]);

        Http::fake();

        $this->artisan('swng:watch-scouts')
            ->expectsOutput('Geen scouts in de wachtrij. Watcher gaat weer slapen.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_command_does_not_alert_on_hard_fail_despite_near_entry(): void
    {
        $position = Position::factory()->create([
            'ticker' => 'PANW',
            'status' => 'scout',
            'entry_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 76.00,
            'bounce_volume_above_average' => true,
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => ['p' => 100.00],
            ]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('swng:watch-scouts')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));

        $position->refresh();
        $this->assertNull($position->telegram_alert_sent_at);
    }

    public function test_command_skips_scout_without_market_data(): void
    {
        Position::factory()->create([
            'ticker' => 'PANW',
            'status' => 'scout',
            'entry_price' => 100.50,
            'latest_sma_20' => null,
            'scout_rsi' => null,
        ]);

        Http::fake([
            'api.polygon.io/*' => Http::response([
                'status' => 'OK',
                'results' => ['p' => 100.00],
            ]),
        ]);

        $this->artisan('swng:watch-scouts')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.polygon.io'));
    }

    public function test_entry_price_change_resets_telegram_alert_timestamp(): void
    {
        $position = Position::factory()->create([
            'ticker' => 'PANW',
            'status' => 'scout',
            'entry_price' => 100.00,
            'telegram_alert_sent_at' => now()->subDays(2),
        ]);

        $position->update(['entry_price' => 105.00]);

        $position->refresh();
        $this->assertNull($position->telegram_alert_sent_at);
    }

    private function createQualifyingScout(string $ticker, float $entryPrice): Position
    {
        return Position::factory()->create([
            'ticker' => $ticker,
            'status' => 'scout',
            'entry_price' => $entryPrice,
            'signal_low' => $entryPrice,
            'latest_close_price' => $entryPrice,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
        ]);
    }
}
