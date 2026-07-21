<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DirectionPnlSplitWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $userId = auth()->id();

        if ($userId === null) {
            return false;
        }

        return app(StrategyAnalyticsService::class)
            ->closedTradesForUser($userId)
            ->isNotEmpty();
    }

    protected function getStats(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        $split = app(StrategyAnalyticsService::class)->pnlSplitByDirection($userId);

        $formatSigned = static function (float $value): string {
            $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');

            return $prefix.'$'.number_format(abs($value), 2);
        };

        return [
            Stat::make('Totale trading P&L', $formatSigned($split['total']))
                ->description(sprintf(
                    'Long: %s | Short: %s · %d gesloten trades',
                    $formatSigned($split['long']),
                    $formatSigned($split['short']),
                    $split['trade_count'],
                ))
                ->color($split['total'] >= 0 ? 'success' : 'danger'),
            Stat::make('Long P&L', $formatSigned($split['long']))
                ->description('Gesloten long-posities')
                ->color($split['long'] >= 0 ? 'success' : 'danger'),
            Stat::make('Short P&L', $formatSigned($split['short']))
                ->description('Gesloten short-posities (Bear Squad)')
                ->color($split['short'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
