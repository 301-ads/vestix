<?php

namespace App\Filament\Widgets;

use App\Services\AlphaTrackerService;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;

class AlphaTrackerChart extends ApexChartWidget
{
    protected static ?string $chartId = 'alphaTrackerEquityCurve';

    protected static ?string $heading = 'Alpha Tracker';

    protected static ?string $description = 'Trading-rendement op gestort kapitaal (stortingen/opnames uitgefilterd; NLV incl. open MTM) — Vestix vs dagelijkse S&P 500 (SPY)';

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
        $portfolio = $this->numericSeries($curve, 'portfolio_pct', carryForward: true);
        $benchmark = $this->numericSeries($curve, 'benchmark_pct', carryForward: false);
        $hasBenchmark = array_filter($benchmark, fn (?float $value): bool => $value !== null) !== [];

        $series = [
            [
                'name' => 'Vestix Portfolio',
                'data' => $portfolio,
            ],
        ];

        if ($hasBenchmark) {
            $series[] = [
                'name' => $benchmarkLabel,
                'data' => $benchmark,
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
                'categories' => array_values(array_map(
                    fn (array $point): string => (string) $point['date'],
                    $curve,
                )),
                'labels' => [
                    'style' => ['colors' => '#71717a', 'fontFamily' => 'inherit'],
                ],
            ],
            'yaxis' => [
                'labels' => [
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
        ];
    }

    /**
     * Apex crashes when series contain unexpected nulls and tooltips call toFixed on them.
     * Portfolio is always a number (carry-forward). Benchmark keeps null gaps for missing SPY days.
     *
     * @param  array<int, array<string, mixed>>  $curve
     * @return list<float|null>
     */
    private function numericSeries(array $curve, string $key, bool $carryForward): array
    {
        $series = [];
        $last = 0.0;

        foreach ($curve as $point) {
            $raw = $point[$key] ?? null;

            if ($raw === null || (is_float($raw) && is_nan($raw))) {
                $series[] = $carryForward ? $last : null;

                continue;
            }

            $value = round((float) $raw, 2);
            $last = $value;
            $series[] = $value;
        }

        return $series;
    }

    protected function extraJsOptions(): ?RawJs
    {
        return RawJs::make(<<<'JS'
        {
            yaxis: {
                labels: {
                    formatter: function (value) {
                        if (value === null || value === undefined || value === '') {
                            return ''
                        }

                        return Number(value).toFixed(1) + '%'
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function (value) {
                        if (value === null || value === undefined || value === '') {
                            return '—'
                        }

                        return Number(value).toFixed(2) + '%'
                    }
                }
            }
        }
        JS);
    }
}
