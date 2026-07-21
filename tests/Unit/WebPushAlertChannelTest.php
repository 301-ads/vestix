<?php

namespace Tests\Unit;

use App\Alerts\Channels\WebPushAlertChannel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushAlertChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_is_unavailable_without_subscription(): void
    {
        $user = User::factory()->create();
        $sender = $this->createMock(WebPushSender::class);
        $sender->method('isConfigured')->willReturn(true);

        $channel = new WebPushAlertChannel($sender);

        $this->assertFalse($channel->isAvailableFor($user));
    }

    public function test_channel_sends_via_sender_when_available(): void
    {
        $user = User::factory()->create();

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example/endpoint',
            'public_key' => 'pk',
            'auth_token' => 'auth',
            'content_encoding' => 'aes128gcm',
        ]);

        $sender = $this->createMock(WebPushSender::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects($this->once())
            ->method('sendToUser')
            ->with(
                $this->callback(fn (User $u): bool => $u->is($user)),
                'Titel regel',
                'Body regel',
            )
            ->willReturn(true);

        $channel = new WebPushAlertChannel($sender);

        $this->assertTrue($channel->isAvailableFor($user));
        $this->assertTrue($channel->send($user, "Titel regel\nBody regel"));
    }

    public function test_channel_uses_message_as_body_when_single_line(): void
    {
        $user = User::factory()->create();

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example/endpoint-2',
            'public_key' => 'pk',
            'auth_token' => 'auth',
            'content_encoding' => 'aes128gcm',
        ]);

        $sender = $this->createMock(WebPushSender::class);
        $sender->expects($this->once())
            ->method('sendToUser')
            ->with($user, 'Vestix', 'Enkelvoudige alert')
            ->willReturn(true);

        $channel = new WebPushAlertChannel($sender);

        $this->assertTrue($channel->send($user, 'Enkelvoudige alert'));
    }
}
