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
        return ['@xl' => 4, '@lg' => 2, 'default' => 1];
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

        return [
            Stat::make('Actieve Scouts', (string) $activeCount)
                ->description('Op je watchlist')
                ->descriptionIcon('heroicon-m-eye')
                ->descriptionColor('success'),
            Stat::make('Top Setups (A+)', (string) $aPlusCount)
                ->description('Hoogste succesratio')
                ->descriptionIcon('heroicon-m-star')
                ->descriptionColor('info'),
            Stat::make('Gem. Gepland Risico', number_format($avgRisk, 2).'%')
                ->description('Per transactie')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor('warning'),
            Stat::make('Klaar voor Executie', (string) $readyCount)
                ->description('Binnen 1% van entry')
                ->descriptionIcon('heroicon-m-bolt')
                ->descriptionColor('gray'),
        ];
    }
}
