<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public static function send(string $message): bool
    {
        $token = config('swng.telegram.bot_token');
        $chatId = config('swng.telegram.chat_id');

        if (! $token || ! $chatId) {
            Log::warning('Telegram not configured — message not sent.');

            return false;
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                Log::warning('Telegram send failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::error('Telegram send exception.', [
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
