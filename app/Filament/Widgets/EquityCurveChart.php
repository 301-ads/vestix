<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class EquityCurveChart extends ApexChartWidget
{
    protected static ?string $chartId = 'strategyEquityCurve';

    protected static ?string $heading = 'Equity Curve';

    protected static ?int $contentHeight = 300;

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
        $userId = auth()->id();

        return $userId !== null
            && app(StrategyAnalyticsService::class)->hasEnoughTrades($userId);
    }

    protected function getOptions(): array
    {
        $userId = auth()->id();
        $direction = StrategyAnalyticsService::resolveDirectionFilter($this->directionFilter);
        $curve = $userId
            ? app(StrategyAnalyticsService::class)->equityCurve($userId, $direction)
            : [];

        return [
            'chart' => [
                'type' => 'line',
                'height' => 300,
                'toolbar' => ['show' => false],
            ],
            'series' => [
                [
                    'name' => 'Cumulatieve ROI %',
                    'data' => array_column($curve, 'cumulative_roi'),
                ],
            ],
            'xaxis' => [
                'categories' => array_column($curve, 'date'),
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'yaxis' => [
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'stroke' => ['curve' => 'smooth', 'width' => 3],
            'colors' => ['#00d492'],
            'grid' => [
                'borderColor' => 'rgba(255,255,255,0.05)',
            ],
        ];
    }
}
