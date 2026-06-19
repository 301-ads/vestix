<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class TagPerformanceChart extends ApexChartWidget
{
    protected static ?string $chartId = 'strategyTagPerformance';

    protected static ?string $heading = 'Winstgevendheid per Strategy Tag';

    protected static ?int $contentHeight = 320;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $userId = auth()->id();

        return $userId !== null
            && app(StrategyAnalyticsService::class)->hasEnoughTrades($userId);
    }

    protected function getOptions(): array
    {
        $userId = auth()->id();
        $perTag = $userId
            ? app(StrategyAnalyticsService::class)->statsPerTag($userId)
            : [];

        return [
            'chart' => [
                'type' => 'bar',
                'height' => 320,
                'toolbar' => ['show' => false],
            ],
            'series' => [
                [
                    'name' => 'Win rate %',
                    'data' => array_column($perTag, 'win_rate'),
                ],
                [
                    'name' => 'Expectancy',
                    'data' => array_column($perTag, 'expectancy'),
                ],
            ],
            'xaxis' => [
                'categories' => array_column($perTag, 'tag_name'),
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'yaxis' => [
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'colors' => ['#00d492', '#3b82f6'],
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
