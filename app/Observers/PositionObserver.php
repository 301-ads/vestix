<?php

namespace App\Observers;

use App\Jobs\CheckPositionAlertTriggersJob;
use App\Jobs\RebuildSquadLeaderboardJob;
use App\Models\Position;
use App\Services\SquadActivityRecorder;
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
            $fresh = $position->fresh();

            if ($original === 'scout' && $new === 'open' && $fresh instanceof Position) {
                app(SquadCopyAlertService::class)->notifySquadMembers(
                    $fresh,
                    'een nieuwe positie geopend op',
                );
                app(SquadActivityRecorder::class)->recordOpened($fresh);
            }

            if ($original === 'open' && $new === 'closed' && $fresh instanceof Position) {
                app(SquadCopyAlertService::class)->notifySquadMembers(
                    $fresh,
                    'een positie gesloten op',
                );
                app(SquadActivityRecorder::class)->recordClosed($fresh);
                RebuildSquadLeaderboardJob::dispatchSync();
            }
        }
    }
}
