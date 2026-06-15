<?php

namespace Tests\Support;

use App\Support\UsMarketSession;

class AlphaVantageFixtures
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function dailyTimeSeries(int $count = 60, float $latestClose = 78.20): array
    {
        $series = [];
        $sessionDate = UsMarketSession::expectedLastCompletedSessionDate();

        for ($day = 1; $day <= $count; $day++) {
            $close = $latestClose - (($count - $day) * 0.01);
            $barDate = $sessionDate->copy()->subWeekdays($count - $day)->toDateString();

            $series[$barDate] = [
                '1. open' => number_format($close - 0.5, 2, '.', ''),
                '2. high' => number_format($close + 1.0, 2, '.', ''),
                '3. low' => number_format($close - 1.0, 2, '.', ''),
                '4. close' => number_format($close, 2, '.', ''),
                '5. adjusted close' => number_format($close, 2, '.', ''),
                '6. volume' => '1000000',
            ];
        }

        return $series;
    }
}
