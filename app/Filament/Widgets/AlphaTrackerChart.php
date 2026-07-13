<?php

namespace App\Filament\Widgets;

use App\Services\AlphaTrackerService;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class AlphaTrackerChart extends ApexChartWidget
{
    protected static ?string $chartId = 'alphaTrackerEquityCurve';

    protected static ?string $heading = 'Alpha Tracker';

    protected static ?string $description = 'Procentuele groei sinds je eerste snapshot — Vestix vs S&P 500 (SPY)';

    protected static ?int $contentHeight = 300;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(AlphaTrackerService::class)->hasEnoughSnapshots($user);
    }

    protected function getOptions(): array
    {
        $user = auth()->user();
        $curve = $user
            ? app(AlphaTrackerService::class)->growthCurve($user)
            : [];

        $benchmarkLabel = 'S&P 500 ('.strtoupper((string) config('vestix.bankroll_tracker.benchmark_ticker', 'SPY')).')';
        $hasBenchmark = collect($curve)->contains(
            fn (array $point): bool => $point['benchmark_pct'] !== null,
        );

        $series = [
            [
                'name' => 'Vestix Portfolio',
                'data' => array_column($curve, 'portfolio_pct'),
            ],
        ];

        if ($hasBenchmark) {
            $series[] = [
                'name' => $benchmarkLabel,
                'data' => array_map(
                    fn (array $point): ?float => $point['benchmark_pct'],
                    $curve,
                ),
            ];
        }

        return [
            'chart' => [
                'type' => 'line',
                'height' => 300,
                'toolbar' => ['show' => false],
            ],
            'series' => $series,
            'xaxis' => [
                'categories' => array_column($curve, 'date'),
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'yaxis' => [
                'labels' => [
                    'formatter' => "function (value) { return value.toFixed(1) + '%'; }",
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
                'title' => [
                    'text' => '% groei',
                    'style' => ['color' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'stroke' => [
                'curve' => 'smooth',
                'width' => [3, 2],
                'dashArray' => [0, 6],
            ],
            'colors' => ['#00d492', '#71717a'],
            'grid' => [
                'borderColor' => 'rgba(255,255,255,0.05)',
            ],
            'legend' => [
                'labels' => ['colors' => '#a1a1aa'],
            ],
            'tooltip' => [
                'y' => [
                    'formatter' => "function (value) { return value.toFixed(2) + '%'; }",
                ],
            ],
        ];
    }
}
