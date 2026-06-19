<?php

namespace App\Alerts\Channels;

use App\Contracts\AlertChannelInterface;
use App\Enums\AlertChannelType;
use App\Models\User;
use App\Support\TelegramNotifier;

class TelegramAlertChannel implements AlertChannelInterface
{
    public function type(): string
    {
        return AlertChannelType::Telegram->value;
    }

    public function send(User $user, string $message): bool
    {
        return TelegramNotifier::sendToUser($user, $message);
    }

    public function isAvailableFor(User $user): bool
    {
        return $user->hasTelegramConnection();
    }
}
