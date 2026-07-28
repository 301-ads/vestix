<?php

namespace App\Console\Commands;

use App\Services\WeeklyEdgeDigestService;
use Illuminate\Console\Command;

class SendWeeklyEdgeDigest extends Command
{
    protected $signature = 'vestix:weekly-edge-digest';

    protected $description = 'Send Weekly Edge Digest (expectancy + protocol) to users with alerts';

    public function handle(WeeklyEdgeDigestService $service): int
    {
        $result = $service->run();

        $this->info("Weekly edge digest sent={$result['sent']} skipped={$result['skipped']}");

        return self::SUCCESS;
    }
}
