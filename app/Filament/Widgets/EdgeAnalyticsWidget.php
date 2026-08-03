<?php

namespace App\Filament\Widgets;

use App\Services\ProtocolComplianceService;
use App\Services\StrategyAnalyticsService;
use Filament\Widgets\Widget;

class EdgeAnalyticsWidget extends Widget
{
    protected string $view = 'filament.widgets.edge-analytics-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $userId = auth()->id();

        return $userId !== null
            && app(StrategyAnalyticsService::class)->closedTradesForUser($userId)->isNotEmpty();
    }

    /**
     * @return array{
     *     stats: array<string, float|int>,
     *     by_grade: list<array{grade: string, trades: int, win_rate: float, expectancy: float}>,
     *     has_grade_breakdown: bool,
     *     ungraded_trades: int,
     *     protocol: array{avg_score: float|null, scored_trades: int, weak_count: int},
     *     until_coach: int
     * }
     */
    public function getPayloadProperty(): array
    {
        $userId = (int) auth()->id();
        $analytics = app(StrategyAnalyticsService::class);
        $byGrade = $analytics->statsByGrade($userId);

        $graded = [];
        $ungradedTrades = 0;

        foreach ($byGrade as $row) {
            if ($row['grade'] === '—') {
                $ungradedTrades += $row['trades'];

                continue;
            }

            $graded[] = $row;
        }

        return [
            'stats' => $analytics->overallStats($userId),
            'by_grade' => $graded,
            'has_grade_breakdown' => $graded !== [],
            'ungraded_trades' => $ungradedTrades,
            'protocol' => app(ProtocolComplianceService::class)->summaryForUser($userId),
            'until_coach' => $analytics->tradesUntilCoach($userId),
        ];
    }
}
