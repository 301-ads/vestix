<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * @deprecated Use ExecutionDigestService / vestix:execution-order-plan (15:31 Gap Reality Check).
 */
class MarketOpenBuyStopReminderService
{
    public function __construct(
        private readonly ExecutionDigestService $executionDigest,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(?Carbon $today = null): array
    {
        $summary = $this->executionDigest->run($today);

        return [
            'sent' => $summary['sent'],
            'skipped' => $summary['skipped'],
        ];
    }
}
