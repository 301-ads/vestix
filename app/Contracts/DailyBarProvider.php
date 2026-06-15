<?php

namespace App\Contracts;

interface DailyBarProvider
{
    /**
     * @return array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }|null
     */
    public function fetchRecentBars(string $ticker, int $lookbackDays = 31, int $limit = 50): ?array;
}
