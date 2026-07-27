<?php

namespace App\Filament\Widgets;

use App\Services\Kluis\VaultService;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class KluisEquityChart extends ApexChartWidget
{
    protected static ?string $chartId = 'kluisEquityCurve';

    protected static ?string $heading = 'Kluis equity';

    protected static ?string $description = 'Cumulatieve cost basis vs holdings-waarde (fills + live tip)';

    protected static ?int $contentHeight = 280;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return count(app(VaultService::class)->equityCurve($user)) >= 1;
    }

    protected function getOptions(): array
    {
        $user = auth()->user();
        $curve = $user ? app(VaultService::class)->equityCurve($user) : [];

        return [
            'chart' => [
                'type' => 'line',
                'height' => 280,
                'toolbar' => ['show' => false],
            ],
            'series' => [
                [
                    'name' => 'Cost basis',
                    'data' => array_column($curve, 'cost_basis'),
                ],
                [
                    'name' => 'Holdings',
                    'data' => array_column($curve, 'holdings_value'),
                ],
            ],
            'xaxis' => [
                'categories' => array_column($curve, 'label'),
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'yaxis' => [
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
                'title' => [
                    'text' => '€',
                    'style' => ['color' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'stroke' => [
                'curve' => 'smooth',
                'width' => 3,
            ],
            'colors' => ['#71717a', '#16a34a'],
            'legend' => [
                'labels' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
            ],
            'dataLabels' => ['enabled' => false],
            'grid' => [
                'borderColor' => '#e4e4e7',
            ],
            'tooltip' => [
                'theme' => 'light',
            ],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        return RawJs::make(<<<'JS'
        {
            yaxis: {
                labels: {
                    formatter: function (val) {
                        return '€' + Number(val).toLocaleString('nl-NL', { maximumFractionDigits: 0 })
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function (val) {
                        return '€' + Number(val).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    }
                }
            }
        }
        JS);
    }
}
