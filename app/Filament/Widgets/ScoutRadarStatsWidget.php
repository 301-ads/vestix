<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ScoutRadarStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 4, '@lg' => 3, 'default' => 2];
    }

    protected function getStats(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        $scouts = Position::scout()->forUser($userId)->get();
        $activeCount = $scouts->count();

        $aPlusCount = $scouts->filter(function (Position $scout): bool {
            if (
                ($scout->signal_low === null && $scout->latest_close_price === null)
                || $scout->latest_sma_20 === null
                || $scout->scout_rsi === null
            ) {
                return false;
            }

            return $scout->evaluateSetupScore()['grade'] === 'A+';
        })->count();

        $riskPercentages = $scouts
            ->map(fn (Position $scout) => $scout->planned_risk_percentage)
            ->filter(fn (?float $value): bool => $value !== null);

        $avgRisk = $riskPercentages->isNotEmpty()
            ? $riskPercentages->avg()
            : 0;

        $readyCount = $scouts->filter(function (Position $scout): bool {
            if ($scout->entry_price === null || $scout->latest_close_price === null || (float) $scout->entry_price <= 0) {
                return false;
            }

            $distance = abs((float) $scout->latest_close_price - (float) $scout->entry_price) / (float) $scout->entry_price;

            return $distance <= 0.01;
        })->count();

        $gapUpCount = $scouts->filter(fn (Position $scout): bool => $scout->hasPremarketGapUpRisk())->count();
        $reclamationCount = $scouts->filter(fn (Position $scout): bool => $scout->hasPremarketReclamation())->count();
        $landingCount = $scouts->filter(fn (Position $scout): bool => $scout->hasPremarketLanding())->count();

        return [
            Stat::make('Actieve Scouts', (string) $activeCount)
                ->description('Op je watchlist')
                ->descriptionIcon('heroicon-m-eye')
                ->descriptionColor('success')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
            Stat::make('Top Setups (A+)', (string) $aPlusCount)
                ->description('Hoogste succesratio')
                ->descriptionIcon('heroicon-m-star')
                ->descriptionColor('info')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue']),
            Stat::make('Gem. Gepland Risico', number_format($avgRisk, 2).'%')
                ->description('Per transactie')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor('warning')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber']),
            Stat::make('Klaar voor Executie', (string) $readyCount)
                ->description('Binnen 1% van entry')
                ->descriptionIcon('heroicon-m-bolt')
                ->descriptionColor('gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--zinc']),
            Stat::make('Gap-up Risico', (string) $gapUpCount)
                ->description('Boven bounce high (14:30)')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor($gapUpCount > 0 ? 'danger' : 'success')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
            Stat::make('Reclamation PM', (string) $reclamationCount)
                ->description('Herovert SMA 20 pre-market')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->descriptionColor($reclamationCount > 0 ? 'success' : 'gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--green']),
            Stat::make('Landing PM', (string) $landingCount)
                ->description('Nadert SMA 20 pre-market')
                ->descriptionIcon('heroicon-m-map-pin')
                ->descriptionColor($landingCount > 0 ? 'warning' : 'gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber']),
        ];
    }
}
