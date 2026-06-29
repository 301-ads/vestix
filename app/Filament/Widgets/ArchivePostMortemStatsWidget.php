<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ArchivePostMortemStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 3, '@lg' => 2, 'default' => 1];
    }

    protected function getStats(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        $analytics = app(StrategyAnalyticsService::class);
        $trades = $analytics->closedTradesForUser($userId);

        if ($trades->isEmpty()) {
            return [
                Stat::make('Profit Factor', '—')
                    ->description('Nog geen gesloten trades')
                    ->descriptionIcon('heroicon-m-calculator')
                    ->descriptionColor('gray')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
                Stat::make('Grootste Misser', '$0.00')
                    ->description('Nog geen gesloten trades')
                    ->descriptionIcon('heroicon-m-shield-exclamation')
                    ->descriptionColor('gray')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--rose']),
                Stat::make('Freeride Hitrate', '0%')
                    ->description('Nog geen gesloten trades')
                    ->descriptionIcon('heroicon-m-shield-check')
                    ->descriptionColor('gray')
                    ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue']),
            ];
        }

        $profitFactor = $analytics->profitFactor($userId);
        $totalWins = (float) $trades
            ->filter(fn ($p) => $p->unrealized_pnl > 0)
            ->sum(fn ($p) => $p->unrealized_pnl);
        $profitFactorLabel = $profitFactor === null && $totalWins > 0
            ? '∞'
            : number_format($profitFactor ?? 0, 2);
        $isProfitable = $profitFactor === null ? $totalWins > 0 : $profitFactor >= 1;

        $biggestLoss = $analytics->biggestLoss($userId);
        $freeride = $analytics->freerideHitRate($userId);

        return [
            Stat::make('Profit Factor', $profitFactorLabel)
                ->description($isProfitable ? 'Wiskundig winstgevend' : 'Wiskundig verliesgevend')
                ->descriptionIcon('heroicon-m-calculator')
                ->descriptionColor($isProfitable ? 'success' : 'danger')
                ->color($isProfitable ? 'success' : 'danger')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
            Stat::make('Grootste Misser', $biggestLoss !== null
                ? '$'.number_format(abs($biggestLoss['dollars']), 2)
                : '$0.00')
                ->description($biggestLoss !== null
                    ? sprintf(
                        '%s · %.1f%% van totale inleg (archief)',
                        $biggestLoss['ticker'],
                        $biggestLoss['pct_of_archive_investment'],
                    )
                    : 'Geen verliezen in archief')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->descriptionColor($biggestLoss !== null ? 'danger' : 'gray')
                ->color($biggestLoss !== null ? 'danger' : 'gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--rose']),
            Stat::make('Freeride Hitrate', number_format($freeride['hit_rate'], 0).'%')
                ->description(sprintf(
                    'In %s%% incasseer je direct je wiskundige Stop Loss',
                    number_format($freeride['miss_rate'], 0),
                ))
                ->descriptionIcon('heroicon-m-shield-check')
                ->descriptionColor($freeride['hit_rate'] >= 40 ? 'success' : 'warning')
                ->color($freeride['hit_rate'] >= 40 ? 'success' : 'warning')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue']),
        ];
    }
}
