<?php

namespace App\Jobs;

use App\Alerts\AlertDispatcher;
use App\Models\Position;
use App\Models\User;
use App\Support\AlertMessageBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendDailyDigestJob implements ShouldQueue
{
    use Queueable;

    public function handle(AlertDispatcher $dispatcher): void
    {
        User::query()->each(function (User $user) use ($dispatcher): void {
            if (! $user->hasTelegramConnection()) {
                return;
            }

            $actionPositions = Position::query()
                ->open()
                ->forUser($user->id)
                ->get()
                ->filter(function (Position $position): bool {
                    $command = $position->action_command;

                    return in_array($command, ['UPDATE', 'STOPPED OUT'], true)
                        || $position->isInDangerZone()
                        || $position->requiresEarningsExit();
                })
                ->values()
                ->all();

            $message = AlertMessageBuilder::dailyDigest($user, $actionPositions);
            $dispatcher->dispatchDigest($user, $message);
        });
    }
}
