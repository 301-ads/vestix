<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use App\Support\FilamentPolling;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PortfolioExposureWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = FilamentPolling::INTERVAL;

    protected static ?int $sort = 1;

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

        $totalInvested = $openPositions->sum(fn (Position $position) => $position->investment);
        $totalValue = $openPositions->sum(fn (Position $position) => $position->current_value);
        $totalPnl = $totalValue - $totalInvested;
        $totalPnlPct = $totalInvested > 0 ? ($totalPnl / $totalInvested) * 100 : 0;
        $totalLockedInProfit = $openPositions->sum(fn (Position $position) => $position->locked_in_profit_dollars);
        $totalCapitalRisk = $openPositions->sum(fn (Position $position) => $position->capital_risk_dollars);
        $openCount = $openPositions->count();

        $pnlPrefix = $totalPnl >= 0 ? '+' : '';
        $pnlPctPrefix = $totalPnlPct >= 0 ? '+' : '';

        return [
            Stat::make('Totale Inleg', '$'.number_format($totalInvested, 2))
                ->description($openCount.' open '.str('positie')->plural($openCount))
                ->descriptionIcon('heroicon-m-banknotes')
                ->descriptionColor('success')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
            Stat::make('Huidige Waarde', '$'.number_format($totalValue, 2))
                ->description('Locked: +$'.number_format($totalLockedInProfit, 2))
                ->descriptionIcon('heroicon-m-shield-check')
                ->descriptionColor('info')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard vestix-stat-card--blue']),
            Stat::make('Open P&L', $pnlPrefix.'$'.number_format(abs($totalPnl), 2))
                ->description($pnlPctPrefix.number_format($totalPnlPct, 2).'% t.o.v. inleg')
                ->descriptionIcon($totalPnl >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($totalPnl >= 0 ? 'success' : 'danger')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard vestix-stat-card--vestix']),
            Stat::make('Kapitaalrisico', '$'.number_format($totalCapitalRisk, 2))
                ->description('Risico op initiële inleg')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($totalCapitalRisk > 0 ? 'warning' : 'success')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard vestix-stat-card--amber']),
        ];
    }
}
