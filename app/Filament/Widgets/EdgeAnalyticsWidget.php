<?php

namespace App\Filament\Widgets;

use App\Services\ProtocolComplianceService;
use App\Services\StrategyAnalyticsService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

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
     *     protocol: array{avg_score: float|null, scored_trades: int, weak_count: int},
     *     until_coach: int
     * }
     */
    public function getPayloadProperty(): array
    {
        $userId = (int) auth()->id();
        $analytics = app(StrategyAnalyticsService::class);

        return [
            'stats' => $analytics->overallStats($userId),
            'by_grade' => $this->statsByGrade($userId, $analytics),
            'protocol' => app(ProtocolComplianceService::class)->summaryForUser($userId),
            'until_coach' => $analytics->tradesUntilCoach($userId),
        ];
    }

    /**
     * @return list<array{grade: string, trades: int, win_rate: float, expectancy: float}>
     */
    private function statsByGrade(int $userId, StrategyAnalyticsService $analytics): array
    {
        $trades = $analytics->closedTradesForUser($userId);
        $grouped = $trades->groupBy(function ($position) {
            return $position->setup_grade ?: '—';
        });

        $rows = [];

        foreach ($grouped as $grade => $group) {
            /** @var Collection $group */
            $wins = $group->filter(fn ($p) => (float) $p->unrealized_pnl_percentage > 0)->count();
            $count = $group->count();
            $avgWin = (float) $group->filter(fn ($p) => (float) $p->unrealized_pnl_percentage > 0)
                ->avg('unrealized_pnl_percentage');
            $avgLoss = (float) $group->filter(fn ($p) => (float) $p->unrealized_pnl_percentage <= 0)
                ->avg('unrealized_pnl_percentage');
            $winRate = $count > 0 ? ($wins / $count) * 100 : 0;
            $expectancy = (($winRate / 100) * $avgWin) + ((1 - ($winRate / 100)) * $avgLoss);

            $rows[] = [
                'grade' => (string) $grade,
                'trades' => $count,
                'win_rate' => round($winRate, 1),
                'expectancy' => round($expectancy, 2),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['trades'] <=> $a['trades']);

        return $rows;
    }
}
