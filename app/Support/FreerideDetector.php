<?php

namespace App\Support;

use App\Enums\AlertEventType;
use App\Jobs\SendAlertJob;
use App\Models\Position;

class FreerideDetector
{
    public function evaluate(Position $position): bool
    {
        if ($position->status !== 'open') {
            return false;
        }

        if ($position->freeride_secured_at !== null) {
            return false;
        }

        if ($position->entry_price === null || $position->current_sl === null) {
            return false;
        }

        if ((float) $position->current_sl <= (float) $position->entry_price) {
            return false;
        }

        $position->updateQuietly(['freeride_secured_at' => now()]);

        SendAlertJob::dispatch(
            $position->user_id,
            $position->id,
            AlertEventType::FreerideSecured,
        );

        return true;
    }
}
