<?php

namespace App\Console\Commands;

use App\Services\TelegramLinkService;
use Illuminate\Console\Command;

class SetTelegramWebhook extends Command
{
    protected $signature = 'vestix:telegram-set-webhook';

    protected $description = 'Registreer de Telegram webhook URL bij Telegram';

    public function handle(TelegramLinkService $telegramLink): int
    {
        if (! filled(config('vestix.telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN ontbreekt in .env');

            return self::FAILURE;
        }

        if (! filled(config('vestix.telegram.webhook_secret'))) {
            $this->error('TELEGRAM_WEBHOOK_SECRET ontbreekt in .env');

            return self::FAILURE;
        }

        $url = $telegramLink->webhookUrl();

        if ($url === null) {
            $this->error('Webhook URL kon niet worden opgebouwd. Controleer APP_URL.');

            return self::FAILURE;
        }

        if (! $telegramLink->registerWebhook()) {
            $this->error('Telegram accepteerde de webhook niet. Check storage/logs/laravel.log');

            return self::FAILURE;
        }

        $this->info("Webhook geregistreerd: {$url}");

        return self::SUCCESS;
    }
}
