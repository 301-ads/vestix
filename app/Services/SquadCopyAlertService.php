<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertChannelType;
use App\Enums\AlertEventType;
use App\Models\Position;
use App\Models\User;
use App\Models\UserAlertPreference;

class SquadCopyAlertService
{
    public function __construct(
        private readonly AlertDispatcher $dispatcher,
    ) {}

    public function notifySquadMembers(Position $position, string $action): void
    {
        $actor = $position->user;

        if (! $actor instanceof User) {
            return;
        }

        $squadIds = $actor->squads()->pluck('squads.id');

        if ($squadIds->isEmpty()) {
            return;
        }

        $recipients = User::query()
            ->whereHas('squads', fn ($q) => $q->whereIn('squads.id', $squadIds))
            ->whereKeyNot($actor->id)
            ->get();

        foreach ($recipients as $recipient) {
            UserAlertPreference::ensureDefaultsForUser($recipient);

            $preference = $recipient->alertPreferences()
                ->where('channel_type', AlertChannelType::Telegram)
                ->where('is_active', true)
                ->first();

            if (! $preference?->hasEventEnabled(AlertEventType::SquadCopyAlert)) {
                continue;
            }

            $this->dispatcher->dispatchNow(
                $recipient->id,
                $position->id,
                AlertEventType::SquadCopyAlert,
                [
                    'actor_name' => $actor->name,
                    'action' => $action,
                ],
            );
        }
    }
}
