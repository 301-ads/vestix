<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    use RefreshDatabase;
    public function test_send_to_user_returns_false_without_chat_id(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake();

        $user = User::factory()->create(['telegram_chat_id' => null]);

        $this->assertFalse(TelegramNotifier::sendToUser($user, 'Test'));
        Http::assertNothingSent();
    }

    public function test_send_to_user_posts_message_to_telegram_api(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $user = User::factory()->create(['telegram_chat_id' => '123456']);

        $this->assertTrue(TelegramNotifier::sendToUser($user, 'BAM! PANW setup bereikt.'));

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
                && $request['chat_id'] === '123456'
                && $request['text'] === 'BAM! PANW setup bereikt.';
        });
    }
}
