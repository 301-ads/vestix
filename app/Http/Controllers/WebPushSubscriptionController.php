<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebPushSubscriptionController extends Controller
{
    public function vapidPublicKey(): JsonResponse
    {
        $publicKey = config('services.webpush.public_key');

        if (! filled($publicKey)) {
            return response()->json(['message' => 'Web Push is niet geconfigureerd.'], 503);
        }

        return response()->json([
            'publicKey' => $publicKey,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
        ]);

        /** @var User $user */
        $user = $request->user();

        PushSubscription::query()->updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $user->id,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => filled($request->userAgent())
                    ? Str::limit($request->userAgent(), 255, '')
                    : null,
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->pushSubscriptions()
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->pushSubscriptions()->delete();

        return response()->json(['ok' => true]);
    }

    public function test(Request $request, WebPushSender $sender): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasPushSubscription()) {
            return response()->json(['message' => 'Geen push-abonnement gevonden.'], 422);
        }

        if (! $sender->sendToUser($user, 'Vestix test', 'Push-notificaties werken op dit apparaat.')) {
            return response()->json(['message' => 'Testbericht kon niet worden verstuurd.'], 500);
        }

        return response()->json(['ok' => true]);
    }
}
