<?php

namespace App\Filament\Widgets;

use App\Services\StrategyAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StrategyCoachStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        $analytics = app(StrategyAnalyticsService::class);

        if (! $analytics->hasEnoughTrades($userId)) {
            $remaining = $analytics->tradesUntilCoach($userId);
            $minimum = $analytics->minTradesForCoach();

            return [
                Stat::make('Strategy Coach', "Nog {$remaining} trades")
                    ->description("Tot je edge zichtbaar wordt (min. {$minimum} gesloten trades)")
                    ->color('gray'),
            ];
        }

        $stats = $analytics->overallStats($userId);
        $insight = $analytics->coachInsight($userId);
        $runner = $analytics->runnerPerformance($userId);

        $coachText = 'Analyseer je tags om je edge te vinden.';

        if ($insight['best'] && $insight['worst'] && ($insight['best']['tag_name'] ?? '') !== ($insight['worst']['tag_name'] ?? '')) {
            $coachText = sprintf(
                'Win rate op %s: %s%% — op %s verlies je in %s%% van de gevallen.',
                $insight['best']['tag_name'],
                number_format($insight['best']['win_rate'], 0),
                $insight['worst']['tag_name'],
                number_format(100 - $insight['worst']['win_rate'], 0),
            );
        }

        return [
            Stat::make('Gesloten trades', (string) $stats['total_trades'])
                ->description('Totaal in journal'),
            Stat::make('Win rate', number_format($stats['win_rate'], 1).'%')
                ->description('Overall hit rate')
                ->color('success'),
            Stat::make('Expectancy', number_format($stats['expectancy'], 2).'%')
                ->description('(WinRate × AvgWin) − (LossRate × AvgLoss)')
                ->color($stats['expectancy'] >= 0 ? 'success' : 'danger'),
            Stat::make('Runner rendement', $runner['scaled_out_trades'] > 0
                ? number_format($runner['runner_beat_target_rate'], 0).'% beat Target 1'
                : '—')
                ->description($runner['scaled_out_trades'] > 0
                    ? sprintf(
                        'Gem. +%sR boven flat %.1fR (%d scale-outs)',
                        number_format($runner['avg_runner_uplift_r'], 2),
                        $runner['avg_flat_target_r'],
                        $runner['scaled_out_trades'],
                    )
                    : 'Nog geen scale-out trades')
                ->color($runner['avg_runner_uplift_r'] > 0 ? 'success' : 'gray'),
            Stat::make('Max drawdown', number_format($stats['max_drawdown'], 2).'%')
                ->description($coachText)
                ->color('warning'),
        ];
    }
}
