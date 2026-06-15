<?php

namespace Tests\Support;

use App\Support\UsMarketSession;

class PolygonFixtures
{
    /**
     * @return list<array{o: float|int, h: float|int, l: float|int, c: float|int, v: int, t: int}>
     */
    public static function dailyBars(int $count = 60, float $latestClose = 78.20, int $volume = 1_000_000): array
    {
        $bars = [];
        $sessionDate = UsMarketSession::expectedLastCompletedSessionDate();

        for ($day = 1; $day <= $count; $day++) {
            $close = $latestClose - (($count - $day) * 0.01);
            $barDate = $sessionDate->copy()->subWeekdays($count - $day);

            $bars[] = [
                'o' => $close - 0.5,
                'h' => $close + 1.0,
                'l' => $close - 1.0,
                'c' => $close,
                'v' => $volume,
                't' => $barDate->timezone('America/New_York')->startOfDay()->timestamp * 1000,
            ];
        }

        return $bars;
    }
}
