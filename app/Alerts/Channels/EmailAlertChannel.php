<?php

namespace App\Alerts\Channels;

use App\Contracts\AlertChannelInterface;
use App\Enums\AlertChannelType;
use App\Mail\AlertNotificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class EmailAlertChannel implements AlertChannelInterface
{
    public function type(): string
    {
        return AlertChannelType::Email->value;
    }

    public function send(User $user, string $message): bool
    {
        try {
            Mail::to($user->email)->send(new AlertNotificationMail($message));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isAvailableFor(User $user): bool
    {
        return filled($user->email);
    }
}
