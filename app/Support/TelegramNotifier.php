<?php

namespace App\Support;

use App\Models\Squad;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    public static function sendToUser(User $user, string $message): bool
    {
        $chatId = $user->resolveTelegramChatId();

        if (! $chatId) {
            Log::warning('Telegram chat ID missing for user.', ['user_id' => $user->id]);

            return false;
        }

        return self::sendToChatId($chatId, $message);
    }

    public static function sendToSquad(Squad $squad, string $message, ?User $except = null): void
    {
        $recipients = $squad->users();

        if ($except !== null) {
            $recipients->whereKeyNot($except->id);
        }

        foreach ($recipients->get() as $user) {
            self::sendToUser($user, $message);
        }
    }

    public static function sendToChatId(string $chatId, string $message): bool
    {
        $token = config('vestix.telegram.bot_token');

        if (! $token) {
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
