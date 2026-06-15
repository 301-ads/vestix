<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;

class FinnhubDailyBarService implements DailyBarProvider
{
    public function __construct(private FinnhubService $finnhub) {}

    public function fetchRecentBars(string $ticker, int $lookbackDays = 31, int $limit = 50): ?array
    {
        return $this->finnhub->fetchRecentBars($ticker, $lookbackDays, $limit);
    }
}
