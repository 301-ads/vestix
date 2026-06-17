<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpenPositionsStatsWidget extends StatsOverviewWidget
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

        $openPositions = Position::open()->forUser($userId)->get();

        if ($openPositions->isEmpty()) {
            return [
                Stat::make('Totaal Open Risico', '$0.00')
                    ->description('Geen open posities')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->descriptionColor('warning')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber']),
                Stat::make('Veiliggestelde Winst', '$0.00')
                    ->description('Risicovrij via Stop-Loss')
                    ->descriptionIcon('heroicon-m-shield-check')
                    ->descriptionColor('info')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue']),
                Stat::make('Winst/Verlies Ratio', '0 / 0')
                    ->description('Geen open posities')
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->descriptionColor('success')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
                Stat::make('In Gevarenzone (< 2%)', '0')
                    ->description('Geen posities in gevarenzone')
                    ->descriptionIcon('heroicon-m-information-circle')
                    ->descriptionColor('gray')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--rose']),
            ];
        }

        $totalRisk = $openPositions->sum(fn (Position $position) => $position->capital_risk_dollars);
        $totalValue = $openPositions->sum(fn (Position $position) => $position->current_value);
        $riskPct = $totalValue > 0 ? ($totalRisk / $totalValue) * 100 : 0;
        $lockedProfit = $openPositions->sum(fn (Position $position) => $position->locked_in_profit_dollars);
        $winners = $openPositions->filter(fn (Position $position) => $position->unrealized_pnl >= 0)->count();
        $losers = $openPositions->filter(fn (Position $position) => $position->unrealized_pnl < 0)->count();
        $winRate = $openPositions->count() > 0 ? ($winners / $openPositions->count()) * 100 : 0;

        $dangerPositions = $openPositions->filter(fn (Position $position) => $position->isInDangerZone());
        $dangerCount = $dangerPositions->count();
        $firstDangerTicker = $dangerPositions->sortBy('ticker')->first()?->ticker;

        return [
            Stat::make('Totaal Open Risico', '$'.number_format($totalRisk, 2))
                ->description(sprintf('Slechts %.2f%% van portfolio', $riskPct))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor('warning')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber']),
            Stat::make('Veiliggestelde Winst', '+$'.number_format($lockedProfit, 2))
                ->description('Risicovrij via Stop-Loss')
                ->descriptionIcon('heroicon-m-shield-check')
                ->descriptionColor('info')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue']),
            Stat::make('Winst/Verlies Ratio', $winners.' / '.$losers)
                ->description(number_format($winRate, 0).'% Win rate (Open)')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->descriptionColor('success')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
            Stat::make('In Gevarenzone (< 2%)', (string) $dangerCount)
                ->description($firstDangerTicker !== null
                    ? $firstDangerTicker.' kruipt naar stop-loss'
                    : 'Geen posities in gevarenzone')
                ->descriptionIcon('heroicon-m-information-circle')
                ->descriptionColor($dangerCount > 0 ? 'danger' : 'gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--rose']),
        ];
    }
}
