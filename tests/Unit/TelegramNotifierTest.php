<?php

namespace Tests\Unit;

use App\Support\TelegramNotifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    public function test_send_returns_false_when_not_configured(): void
    {
        config([
            'swng.telegram.bot_token' => null,
            'swng.telegram.chat_id' => null,
        ]);

        Http::fake();

        $this->assertFalse(TelegramNotifier::send('Test'));
        Http::assertNothingSent();
    }

    public function test_send_posts_message_to_telegram_api(): void
    {
        config([
            'swng.telegram.bot_token' => 'test-token',
            'swng.telegram.chat_id' => '123456',
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->assertTrue(TelegramNotifier::send('BAM! PANW setup bereikt.'));

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.telegram.org/bottest-token/sendMessage')
                && $request['chat_id'] === '123456'
                && $request['text'] === 'BAM! PANW setup bereikt.';
        });
    }
}
