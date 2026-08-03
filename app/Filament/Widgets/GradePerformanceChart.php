<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use App\Support\SetupGradeColors;
use App\Support\StrategyCoachDemoPreview;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class GradePerformanceChart extends ApexChartWidget
{
    protected static ?string $chartId = 'strategyGradePerformance';

    protected static ?string $heading = 'Winstgevendheid per setup-grade';

    protected static ?int $contentHeight = 320;

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

        parent::mount();
    }

    public function onCoachDirectionUpdated(string $filter): void
    {
        $this->directionFilter = $filter;
        $this->updateOptions();
    }

    public static function canView(): bool
    {
        if (StrategyCoachDemoPreview::enabled()) {
            return true;
        }

        $userId = auth()->id();

        return $userId !== null
            && app(StrategyAnalyticsService::class)->hasEnoughTrades($userId);
    }

    protected function getOptions(): array
    {
        $userId = auth()->id();
        $direction = StrategyAnalyticsService::resolveDirectionFilter($this->directionFilter);
        $byGrade = StrategyCoachDemoPreview::enabled()
            ? StrategyCoachDemoPreview::statsByGrade()
            : ($userId
                ? app(StrategyAnalyticsService::class)->statsByGrade($userId, $direction)
                : []);

        // Skip ungraded "—" buckets — same rule as Edge analytics.
        $byGrade = array_values(array_filter(
            $byGrade,
            static fn (array $row): bool => ($row['grade'] ?? '') !== '—',
        ));

        $grades = array_column($byGrade, 'grade');
        $labelColors = array_map(
            static fn (string $grade): string => SetupGradeColors::chartLabel($grade),
            $grades,
        );

        return [
            'chart' => [
                'type' => 'bar',
                'height' => 320,
                'toolbar' => ['show' => false],
            ],
            'series' => [
                [
                    'name' => 'Win rate %',
                    'data' => array_column($byGrade, 'win_rate'),
                ],
                [
                    'name' => 'Expectancy',
                    'data' => array_column($byGrade, 'expectancy'),
                ],
            ],
            'xaxis' => [
                'categories' => $grades,
                'labels' => [
                    'style' => [
                        'colors' => $labelColors,
                        'fontFamily' => 'inherit',
                        'fontWeight' => 600,
                    ],
                ],
            ],
            'yaxis' => [
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'colors' => [SetupGradeColors::A_PLUS, '#3b82f6'],
            'plotOptions' => [
                'bar' => [
                    'borderRadius' => 4,
                    'columnWidth' => '55%',
                ],
            ],
            'legend' => [
                'labels' => ['colors' => '#a1a1aa'],
            ],
            'grid' => [
                'borderColor' => 'rgba(255,255,255,0.05)',
            ],
        ];
    }
}
