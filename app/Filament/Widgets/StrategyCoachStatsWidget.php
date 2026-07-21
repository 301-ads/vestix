<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use App\Support\StrategyCoachDemoPreview;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StrategyCoachStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'coach-direction-updated' => 'onCoachDirectionUpdated',
    ];

    public string $directionFilter = 'all';

    public function mount(): void
    {
        $this->directionFilter = (string) session('vestix.coach_direction_filter', 'all');
    }

    public function onCoachDirectionUpdated(string $filter): void
    {
        $this->directionFilter = $filter;
    }

    protected function getStats(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        if (StrategyCoachDemoPreview::enabled()) {
            return $this->demoStats();
        }

        $analytics = app(StrategyAnalyticsService::class);
        $direction = StrategyAnalyticsService::resolveDirectionFilter($this->directionFilter);

        if (! $analytics->hasEnoughTrades($userId, $direction)) {
            $remaining = $analytics->tradesUntilCoach($userId, $direction);
            $minimum = $analytics->minTradesForCoach();
            $scope = match ($direction?->value) {
                'long' => 'longs',
                'short' => 'shorts',
                default => 'trades',
            };

            return [
                Stat::make('Edge-analyse', "Nog {$remaining} {$scope}")
                    ->description("Tot je edge zichtbaar wordt (min. {$minimum} gesloten trades)")
                    ->color('gray'),
            ];
        }

        $stats = $analytics->overallStats($userId, $direction);
        $insight = $analytics->coachInsight($userId, $direction);
        $runner = $analytics->runnerPerformance($userId, $direction);

        $coachText = 'Analyseer je tags om je edge te vinden.';

        if ($insight['best'] && $insight['worst'] && ($insight['best']['tag_name'] ?? '') !== ($insight['worst']['tag_name'] ?? '')) {
            $coachText = sprintf(
                'Win rate op %s: %s%% — op %s verlies je in %s%% van de gevallen.',
                $insight['best']['tag_name'],
                number_format($insight['best']['win_rate'], 0),
                $insight['worst']['tag_name'],
                number_format(100 - $insight['worst']['win_rate'], 0),
            );
        }

        return $this->buildStats(
            totalTrades: (string) $stats['total_trades'],
            winRate: number_format($stats['win_rate'], 1).'%',
            expectancy: number_format($stats['expectancy'], 2).'%',
            expectancyPositive: $stats['expectancy'] >= 0,
            runnerValue: $runner['scaled_out_trades'] > 0
                ? number_format($runner['runner_beat_target_rate'], 0).'% beat Target 1'
                : '—',
            runnerDescription: $runner['scaled_out_trades'] > 0
                ? sprintf(
                    'Gem. +%sR boven flat %.1fR (%d scale-outs)',
                    number_format($runner['avg_runner_uplift_r'], 2),
                    $runner['avg_flat_target_r'],
                    $runner['scaled_out_trades'],
                )
                : 'Nog geen scale-out trades',
            runnerPositive: $runner['avg_runner_uplift_r'] > 0,
            maxDrawdown: number_format($stats['max_drawdown'], 2).'%',
            coachText: $coachText,
        );
    }

    /**
     * @return array<int, Stat>
     */
    private function demoStats(): array
    {
        $demo = StrategyCoachDemoPreview::stats();

        return $this->buildStats(
            totalTrades: (string) $demo['total_trades'],
            winRate: number_format($demo['win_rate'], 1).'%',
            expectancy: number_format($demo['expectancy'], 2).'%',
            expectancyPositive: true,
            runnerValue: number_format($demo['runner_beat_target_rate'], 0).'% beat Target 1',
            runnerDescription: sprintf(
                'Gem. +%sR boven flat %.1fR (%d scale-outs) · demo',
                number_format($demo['avg_runner_uplift_r'], 2),
                $demo['avg_flat_target_r'],
                $demo['scaled_out_trades'],
            ),
            runnerPositive: true,
            maxDrawdown: number_format($demo['max_drawdown'], 2).'%',
            coachText: $demo['coach_text'],
        );
    }

    /**
     * @return array<int, Stat>
     */
    private function buildStats(
        string $totalTrades,
        string $winRate,
        string $expectancy,
        bool $expectancyPositive,
        string $runnerValue,
        string $runnerDescription,
        bool $runnerPositive,
        string $maxDrawdown,
        string $coachText,
    ): array {
        return [
            Stat::make('Gesloten trades', $totalTrades)
                ->description('Totaal in journal'),
            Stat::make('Win rate', $winRate)
                ->description('Overall hit rate')
                ->color('success'),
            Stat::make('Expectancy', $expectancy)
                ->description('(WinRate × AvgWin) − (LossRate × AvgLoss)')
                ->color($expectancyPositive ? 'success' : 'danger'),
            Stat::make('Runner rendement', $runnerValue)
                ->description($runnerDescription)
                ->color($runnerPositive ? 'success' : 'gray'),
            Stat::make('Max drawdown', $maxDrawdown)
                ->description($coachText)
                ->color('warning'),
        ];
    }
}
