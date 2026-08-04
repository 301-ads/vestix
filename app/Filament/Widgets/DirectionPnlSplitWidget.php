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

        return app(StrategyAnalyticsService::class)->hasTradePnlPositions($userId);
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

        $closedCount = (int) $split['closed_trade_count'];
        $openCount = (int) $split['open_trade_count'];

        return [
            Stat::make('Totale trading P&L', $formatSigned($split['total']))
                ->description(sprintf(
                    'Gesloten: %s · Open: %s · %d gesloten + %d open',
                    $formatSigned($split['closed_total']),
                    $formatSigned($split['open_total']),
                    $closedCount,
                    $openCount,
                ))
                ->color($split['total'] >= 0 ? 'success' : 'danger'),
            Stat::make('Long P&L', $formatSigned($split['long']))
                ->description(sprintf(
                    'Gesloten: %s · Open (MTM): %s',
                    $formatSigned($split['closed_long']),
                    $formatSigned($split['open_long']),
                ))
                ->color($split['long'] >= 0 ? 'success' : 'danger'),
            Stat::make('Short P&L', $formatSigned($split['short']))
                ->description(sprintf(
                    'Gesloten: %s · Open (MTM): %s',
                    $formatSigned($split['closed_short']),
                    $formatSigned($split['open_short']),
                ))
                ->color($split['short'] >= 0 ? 'success' : 'danger'),
        ];
    }
}
