<?php

namespace App\Console\Commands;

use App\Support\TelegramNotifier;
use Illuminate\Console\Command;

class TestTelegram extends Command
{
    protected $signature = 'swng:test-telegram';

    protected $description = 'Stuurt een testbericht naar je geconfigureerde Telegram-chat.';

    public function handle(): int
    {
        if (! filled(config('swng.telegram.bot_token')) || ! filled(config('swng.telegram.chat_id'))) {
            $this->error('TELEGRAM_BOT_TOKEN of TELEGRAM_CHAT_ID ontbreekt in .env');

            return self::FAILURE;
        }

        $message = '🎯 SWNG test — je Telegram-koppeling werkt!';

        if (! TelegramNotifier::send($message)) {
            $this->error('Telegram-verzending mislukt. Check storage/logs/laravel.log voor details.');

            return self::FAILURE;
        }

        $this->info('Testbericht verstuurd! Check je Telegram-app.');

        return self::SUCCESS;
    }
}
