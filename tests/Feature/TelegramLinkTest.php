<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TelegramLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resolve_telegram_chat_id_has_no_global_fallback(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        $user = User::factory()->create(['telegram_chat_id' => null]);

        $this->assertNull($user->resolveTelegramChatId());
    }

    public function test_webhook_links_user_from_start_command(): void
    {
        config([
            'vestix.telegram.bot_token' => 'test-token',
            'vestix.telegram.webhook_secret' => 'super-secret',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $user = User::factory()->create([
            'telegram_chat_id' => null,
            'telegram_link_token' => 'abc123token',
        ]);

        $this->postJson('/telegram/webhook/super-secret', [
            'message' => [
                'text' => '/start link_abc123token',
                'chat' => ['id' => 987654321],
            ],
        ])->assertOk();

        $user->refresh();

        $this->assertSame('987654321', $user->telegram_chat_id);
        $this->assertNull($user->telegram_link_token);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && ($request['chat_id'] ?? null) === '987654321');
    }

    public function test_webhook_rejects_invalid_secret(): void
    {
        config(['vestix.telegram.webhook_secret' => 'super-secret']);

        $this->postJson('/telegram/webhook/wrong-secret', [
            'message' => [
                'text' => '/start link_abc',
                'chat' => ['id' => 1],
            ],
        ])->assertNotFound();
    }

    public function test_link_url_contains_bot_username_and_token(): void
    {
        config([
            'vestix.telegram.bot_username' => 'vestix_bot',
        ]);

        $user = User::factory()->create();

        $url = app(TelegramLinkService::class)->linkUrlFor($user);

        $this->assertNotNull($user->fresh()->telegram_link_token);
        $this->assertStringStartsWith('https://t.me/vestix_bot?start=link_', $url);
    }
}
