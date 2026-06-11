<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PortfolioExposureWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int | array | null
    {
        return ['@xl' => 4, '@lg' => 2, 'default' => 1];
    }

    protected function getStats(): array
    {
        $openPositions = Position::open()->get();

        $totalInvested = $openPositions->sum(fn (Position $position) => $position->investment);
        $totalValue = $openPositions->sum(fn (Position $position) => $position->current_value);
        $totalPnl = $totalValue - $totalInvested;
        $totalPnlPct = $totalInvested > 0 ? ($totalPnl / $totalInvested) * 100 : 0;
        $totalRisk = $openPositions->sum(fn (Position $position) => $position->risk_dollars);
        $openCount = $openPositions->count();

        $pnlPrefix = $totalPnl >= 0 ? '+' : '';
        $pnlPctPrefix = $totalPnlPct >= 0 ? '+' : '';

        return [
            Stat::make('Totale Inleg', '$'.number_format($totalInvested, 2))
                ->description($openCount.' open '.str('positie')->plural($openCount))
                ->descriptionIcon('heroicon-m-banknotes')
                ->descriptionColor('success'),
            Stat::make('Huidige Waarde', '$'.number_format($totalValue, 2))
                ->description('Marktwaarde portfolio')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->descriptionColor('info'),
            Stat::make('Open P&L', $pnlPrefix.'$'.number_format(abs($totalPnl), 2))
                ->description($pnlPctPrefix.number_format($totalPnlPct, 2).'% t.o.v. inleg')
                ->descriptionIcon($totalPnl >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($totalPnl >= 0 ? 'success' : 'danger'),
            Stat::make('Maximaal Risico', '$'.number_format($totalRisk, 2))
                ->description('Tot aan stop-loss schilden')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($totalRisk > 0 ? 'warning' : 'success'),
        ];
    }
}
