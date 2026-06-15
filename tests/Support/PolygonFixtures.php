<?php

namespace Tests\Support;

class PolygonFixtures
{
    /**
     * @return list<array{o: float|int, h: float|int, l: float|int, c: float|int, v: int, t: int}>
     */
    public static function dailyBars(int $count = 60, float $latestClose = 78.20, int $volume = 1_000_000): array
    {
        $bars = [];

        for ($day = 1; $day <= $count; $day++) {
            $close = $latestClose - (($count - $day) * 0.01);

            $bars[] = [
                'o' => $close - 0.5,
                'h' => $close + 1.0,
                'l' => $close - 1.0,
                'c' => $close,
                'v' => $volume,
                't' => now()->subDays($count - $day)->startOfDay()->timestamp * 1000,
            ];
        }

        return $bars;
    }
}
