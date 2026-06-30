<?php

namespace App\Observers;

use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\RebuildSquadLeaderboardJob;
use App\Models\Position;
use App\Services\SquadCopyAlertService;
use App\Support\FreerideDetector;

class PositionObserver
{
    public function updated(Position $position): void
    {
        if ($position->wasChanged('current_sl') && $position->status === 'open') {
            app(FreerideDetector::class)->evaluate($position->fresh());
            CheckPositionAlertTriggersJob::dispatch($position->id);
        }

        if ($position->wasChanged('status')) {
            $original = $position->getOriginal('status');
            $new = $position->status;

            if ($original === 'scout' && $new === 'open') {
                app(SquadCopyAlertService::class)->notifySquadMembers(
                    $position->fresh(),
                    'een nieuwe positie geopend op',
                );
            }

            if ($original === 'open' && $new === 'closed') {
                app(SquadCopyAlertService::class)->notifySquadMembers(
                    $position->fresh(),
                    'een positie gesloten op',
                );
                RebuildSquadLeaderboardJob::dispatchSync();
            }
        }
    }
}
