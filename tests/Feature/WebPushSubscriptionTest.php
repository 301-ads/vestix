<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.webpush.subject' => 'mailto:test@vestix.test',
            'services.webpush.public_key' => 'BBLcZE3DkZ1llsZ8lKPk1XGIp_NO_s0etD_ib5As_z9drjc6AR2Ls3Rt4QWTvwqEPcB0yzWFTE3VM6n5ci9vrrI',
            'services.webpush.private_key' => 'o5Aba0KZpcB5BeT0Hlsc_UEXxW7f-DmOBK05sZmz4Oc',
        ]);
    }

    public function test_guest_cannot_subscribe(): void
    {
        $this->postJson('/admin/webpush/subscribe', [
            'endpoint' => 'https://push.example/endpoint-1',
            'keys' => [
                'p256dh' => 'public-key',
                'auth' => 'auth-token',
            ],
        ])->assertRedirect(route('filament.admin.auth.login'));

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_user_can_subscribe_and_unsubscribe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/admin/webpush/subscribe', [
                'endpoint' => 'https://push.example/endpoint-1',
                'keys' => [
                    'p256dh' => 'public-key',
                    'auth' => 'auth-token',
                ],
                'contentEncoding' => 'aes128gcm',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => 'https://push.example/endpoint-1',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ]);

        $this->actingAs($user)
            ->deleteJson('/admin/webpush/subscribe', [
                'endpoint' => 'https://push.example/endpoint-1',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://push.example/endpoint-1',
        ]);
    }

    public function test_vapid_public_key_endpoint(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/admin/webpush/vapid-public-key')
            ->assertOk()
            ->assertJson([
                'publicKey' => 'BBLcZE3DkZ1llsZ8lKPk1XGIp_NO_s0etD_ib5As_z9drjc6AR2Ls3Rt4QWTvwqEPcB0yzWFTE3VM6n5ci9vrrI',
            ]);
    }

    public function test_test_endpoint_requires_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/admin/webpush/test')
            ->assertStatus(422);
    }

    public function test_test_endpoint_sends_when_subscribed(): void
    {
        $user = User::factory()->create();

        PushSubscription::query()->create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example/endpoint-test',
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        $this->mock(WebPushSender::class, function ($mock): void {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->andReturn(true);
        });

        $this->actingAs($user)
            ->postJson('/admin/webpush/test')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }
}
