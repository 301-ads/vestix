<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\TelegramNotifier;
use Illuminate\Console\Command;

class TestTelegram extends Command
{
    protected $signature = 'vestix:test-telegram {email? : E-mailadres van de gebruiker om te testen}';

    protected $description = 'Stuurt een testbericht naar de gekoppelde Telegram-chat van een gebruiker.';

    public function handle(): int
    {
        if (! filled(config('vestix.telegram.bot_token'))) {
            $this->error('TELEGRAM_BOT_TOKEN ontbreekt in .env');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        $user = is_string($email) && $email !== ''
            ? User::query()->where('email', $email)->first()
            : null;

        if ($user === null && is_string($email) && $email !== '') {
            $this->error("Geen gebruiker gevonden met e-mail: {$email}");

            return self::FAILURE;
        }

        if ($user === null) {
            $this->error('Geef een e-mailadres op: php artisan vestix:test-telegram you@example.com');

            return self::FAILURE;
        }

        if (! $user->hasTelegramConnection()) {
            $this->error("{$user->email} heeft nog geen Telegram gekoppeld (Profiel → Koppel Telegram).");

            return self::FAILURE;
        }

        $message = '🎯 Vestix test — je Telegram-koppeling werkt!';

        if (! TelegramNotifier::sendToUser($user, $message)) {
            $this->error('Telegram-verzending mislukt. Check storage/logs/laravel.log voor details.');

            return self::FAILURE;
        }

        $this->info("Testbericht verstuurd naar {$user->email}!");

        return self::SUCCESS;
    }
}
