<?php

namespace App\Support;

class StrategyCoachDemoPreview
{
    public static function enabled(): bool
    {
        if (! (bool) config('vestix.strategy_coach.demo_preview', false)) {
            return false;
        }

        if (app()->environment('local')) {
            return true;
        }

        // Opt-in for feature tests only — never on in production/staging.
        return app()->environment('testing')
            && (bool) config('vestix.strategy_coach.force_demo_in_tests', false);
    }

    /**
     * @return array{
     *     total_trades: int,
     *     win_rate: float,
     *     expectancy: float,
     *     max_drawdown: float,
     *     coach_text: string,
     *     runner_beat_target_rate: float,
     *     avg_runner_uplift_r: float,
     *     avg_flat_target_r: float,
     *     scaled_out_trades: int,
     * }
     */
    public static function stats(): array
    {
        return [
            'total_trades' => 24,
            'win_rate' => 62.5,
            'expectancy' => 1.85,
            'max_drawdown' => 8.40,
            'coach_text' => 'Win rate op Trampoline Bounce: 68% — op Early Entry verlies je in 55% van de gevallen.',
            'runner_beat_target_rate' => 67.0,
            'avg_runner_uplift_r' => 0.85,
            'avg_flat_target_r' => 2.0,
            'scaled_out_trades' => 12,
        ];
    }

    /**
     * @return list<array{date: string, cumulative_roi: float}>
     */
    public static function equityCurve(): array
    {
        $points = [
            ['2026-03-03', 2.1],
            ['2026-03-10', 3.4],
            ['2026-03-17', 1.8],
            ['2026-03-24', 4.6],
            ['2026-04-01', 6.2],
            ['2026-04-08', 5.1],
            ['2026-04-15', 7.8],
            ['2026-04-22', 9.4],
            ['2026-04-29', 8.1],
            ['2026-05-06', 11.2],
            ['2026-05-13', 13.5],
            ['2026-05-20', 12.0],
            ['2026-05-27', 15.4],
            ['2026-06-03', 17.8],
            ['2026-06-10', 16.2],
            ['2026-06-17', 19.6],
            ['2026-06-24', 21.3],
            ['2026-07-01', 20.1],
            ['2026-07-08', 23.4],
            ['2026-07-15', 25.8],
        ];

        return array_map(
            fn (array $row): array => [
                'date' => $row[0],
                'cumulative_roi' => $row[1],
            ],
            $points,
        );
    }
}
