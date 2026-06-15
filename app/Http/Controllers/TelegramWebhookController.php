<?php

namespace App\Http\Controllers;

use App\Services\TelegramLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, TelegramLinkService $telegramLink): Response
    {
        if ($secret !== config('vestix.telegram.webhook_secret')) {
            abort(404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $telegramLink->handleWebhookUpdate($payload);

        return response('ok');
    }
}
