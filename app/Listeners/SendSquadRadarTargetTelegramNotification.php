<?php

namespace App\Listeners;

use App\Enums\SquadRole;
use App\Events\SquadRadarTargetPosted;
use App\Services\SquadPermissionService;
use App\Support\TelegramNotifier;
use Spatie\Permission\PermissionRegistrar;

class SendSquadRadarTargetTelegramNotification
{
    public function __construct(private SquadPermissionService $permissions) {}

    public function handle(SquadRadarTargetPosted $event): void
    {
        $position = $event->position->loadMissing(['user', 'squad']);

        if ($position->squad === null) {
            return;
        }

        $score = $position->evaluateSetupScore();
        $author = $position->user?->name ?? 'Onbekend';
        $message = sprintf(
            'Nieuw doelwit gespot door %s: %s (Score: %d/%d).',
            $author,
            $position->ticker,
            $score['totalPoints'],
            $score['maxPoints'],
        );

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($position->squad_id);

        $recipients = $position->squad->users()
            ->whereKeyNot($position->user_id)
            ->get()
            ->filter(function ($user) {
                return $user->hasRole([
                    SquadRole::Sniper->value,
                    SquadRole::Commander->value,
                ]);
            });

        $registrar->setPermissionsTeamId(null);

        foreach ($recipients as $recipient) {
            TelegramNotifier::sendToUser($recipient, $message);
        }
    }
}
