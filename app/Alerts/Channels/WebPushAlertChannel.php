<?php

namespace App\Alerts\Channels;

use App\Contracts\AlertChannelInterface;
use App\Enums\AlertChannelType;
use App\Models\User;
use App\Services\WebPushSender;

class WebPushAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private readonly ?WebPushSender $sender = null,
    ) {}

    public function type(): string
    {
        return AlertChannelType::WebPush->value;
    }

    public function send(User $user, string $message): bool
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($message)) ?: [];
        $title = trim((string) ($lines[0] ?? 'Vestix')) ?: 'Vestix';
        $body = trim(implode("\n", array_slice($lines, 1)));

        if ($body === '') {
            $body = $title;
            $title = 'Vestix';
        }

        return $this->sender()->sendToUser($user, $title, $body);
    }

    public function isAvailableFor(User $user): bool
    {
        return $this->sender()->isConfigured() && $user->hasPushSubscription();
    }

    private function sender(): WebPushSender
    {
        return $this->sender ?? app(WebPushSender::class);
    }
}
