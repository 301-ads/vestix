<?php

namespace App\Support;

use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class FilamentNotifier
{
    /**
     * @param  Model|Authenticatable|Collection<int, Model|Authenticatable>|array<int, Model|Authenticatable>|null  $recipients
     */
    public static function send(
        string $title,
        ?string $body = null,
        string $status = 'success',
        Model|Authenticatable|Collection|array|null $recipients = null,
    ): void {
        $notification = Notification::make()->title($title);

        if (filled($body)) {
            $notification->body($body);
        }

        match ($status) {
            'warning' => $notification->warning(),
            'danger' => $notification->danger(),
            'info' => $notification->info(),
            default => $notification->success(),
        };

        if (request()->hasSession()) {
            $notification->send();
        }

        $recipients ??= auth()->user();

        if ($recipients === null) {
            return;
        }

        if (! is_iterable($recipients)) {
            $recipients = [$recipients];
        }

        foreach ($recipients as $recipient) {
            $recipient->notifyNow($notification->toDatabase());
            DatabaseNotificationsSent::dispatch($recipient);
        }
    }
}
