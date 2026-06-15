<?php

namespace App\Listeners;

use App\Events\PositionLiquidated;
use App\Support\TelegramNotifier;

class SendPositionLiquidatedTelegramNotification
{
    public function handle(PositionLiquidated $event): void
    {
        $position = $event->position->loadMissing('user');

        if ($position->user === null) {
            return;
        }

        $message = sprintf(
            'Liquidatie-waarschuwing: %s heeft de stop-loss geraakt.',
            $position->ticker,
        );

        TelegramNotifier::sendToUser($position->user, $message);
    }
}
