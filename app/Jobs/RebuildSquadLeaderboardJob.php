<?php

namespace App\Jobs;

use App\Services\PositionStatsAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RebuildSquadLeaderboardJob implements ShouldQueue
{
    use Queueable;

    public function handle(PositionStatsAggregator $aggregator): void
    {
        $aggregator->rebuildAll();
    }
}
