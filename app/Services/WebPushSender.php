<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushSender
{
    public function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key'))
            && filled(config('services.webpush.private_key'))
            && filled(config('services.webpush.subject'));
    }

    public function sendToUser(User $user, string $title, string $body, ?string $url = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url ?? url('/admin'),
            'icon' => asset('images/favicon-192x192.png'),
        ], JSON_THROW_ON_ERROR);

        $webPush = $this->makeClient();
        $sent = false;

        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                    ]),
                    $payload,
                );
            } catch (Throwable $exception) {
                Log::warning('webpush.queue_failed', [
                    'subscription_id' => $subscription->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent = true;

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()
                    ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
                    ->delete();

                continue;
            }

            Log::warning('webpush.send_failed', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

    protected function makeClient(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('services.webpush.subject'),
                'publicKey' => (string) config('services.webpush.public_key'),
                'privateKey' => (string) config('services.webpush.private_key'),
            ],
        ]);
    }
}
