<?php

namespace App\Services;

use App\Models\User;
use App\Support\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function linkUrlFor(User $user): ?string
    {
        $username = $this->botUsername();

        if ($username === null) {
            return null;
        }

        $token = $user->ensureTelegramLinkToken();

        return "https://t.me/{$username}?start=link_{$token}";
    }

    public function botUsername(): ?string
    {
        $configured = config('vestix.telegram.bot_username');

        if (is_string($configured) && $configured !== '') {
            return ltrim($configured, '@');
        }

        return Cache::remember('vestix:telegram_bot_username', now()->addDay(), function (): ?string {
            $token = config('vestix.telegram.bot_token');

            if (! is_string($token) || $token === '') {
                return null;
            }

            try {
                $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");

                if (! $response->successful() || ! ($response->json('ok') ?? false)) {
                    return null;
                }

                $username = $response->json('result.username');

                return is_string($username) && $username !== '' ? $username : null;
            } catch (\Throwable $exception) {
                Log::warning('Telegram getMe failed.', [
                    'message' => $exception->getMessage(),
                ]);

                return null;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function handleWebhookUpdate(array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? null;
        $text = $message['text'] ?? null;

        if (! is_array($chat) || ! is_string($text)) {
            return;
        }

        $chatId = $chat['id'] ?? null;

        if (! is_numeric($chatId)) {
            return;
        }

        $chatId = (string) $chatId;

        if (str_starts_with($text, '/start link_')) {
            $this->linkUserFromStartCommand($chatId, $text);

            return;
        }

        if (str_starts_with($text, '/start')) {
            TelegramNotifier::sendToChatId(
                $chatId,
                'Open Vestix → Profiel → Koppel Telegram en druk op Start om je account te koppelen.',
            );
        }
    }

    private function linkUserFromStartCommand(string $chatId, string $text): void
    {
        $token = Str::after($text, '/start link_');

        if ($token === '' || strlen($token) > 64) {
            TelegramNotifier::sendToChatId($chatId, 'Ongeldige koppellink. Genereer een nieuwe link in Vestix.');

            return;
        }

        $user = User::query()->where('telegram_link_token', $token)->first();

        if (! $user instanceof User) {
            TelegramNotifier::sendToChatId($chatId, 'Deze koppellink is verlopen. Genereer een nieuwe link in Vestix.');

            return;
        }

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_link_token' => null,
        ])->save();

        TelegramNotifier::sendToChatId(
            $chatId,
            'Vestix gekoppeld! Je ontvangt nu persoonlijke alerts in deze chat.',
        );
    }

    public function webhookUrl(): ?string
    {
        $secret = config('vestix.telegram.webhook_secret');
        $appUrl = rtrim((string) config('app.url'), '/');

        if (! is_string($secret) || $secret === '' || $appUrl === '') {
            return null;
        }

        return "{$appUrl}/telegram/webhook/{$secret}";
    }

    public function registerWebhook(): bool
    {
        return $this->registerWebhookWithDetails()['ok'];
    }

    /**
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    public function registerWebhookWithDetails(): array
    {
        $token = config('vestix.telegram.bot_token');
        $url = $this->webhookUrl();

        if (! is_string($token) || $token === '' || $url === null) {
            return [
                'ok' => false,
                'url' => $url,
                'error' => 'TELEGRAM_BOT_TOKEN of webhook URL ontbreekt.',
            ];
        }

        if (! str_starts_with($url, 'https://')) {
            return [
                'ok' => false,
                'url' => $url,
                'error' => 'Webhook URL moet met https:// beginnen. Zet APP_URL=https://vestix.io in Forge Environment.',
            ];
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => $url,
                'allowed_updates' => ['message'],
            ]);

            $body = $response->json();
            $ok = $response->successful() && ($body['ok'] ?? false);

            if ($ok) {
                return ['ok' => true, 'url' => $url, 'error' => null];
            }

            $error = is_string($body['description'] ?? null)
                ? $body['description']
                : 'Onbekende Telegram-fout.';

            Log::warning('Telegram setWebhook rejected.', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $body,
            ]);

            return ['ok' => false, 'url' => $url, 'error' => $error];
        } catch (\Throwable $exception) {
            Log::error('Telegram setWebhook failed.', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'url' => $url,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
