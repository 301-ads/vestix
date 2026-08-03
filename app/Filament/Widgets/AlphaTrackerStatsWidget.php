<?php

namespace App\Filament\Widgets;

use App\Services\AlphaTrackerService;
use App\Support\FilamentPolling;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AlphaTrackerStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = FilamentPolling::INTERVAL;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 3, '@lg' => 3, 'default' => 1];
    }

    public static function canView(): bool
    {
        $userId = auth()->id();

        return $userId !== null
            && app(AlphaTrackerService::class)->hasEnoughSnapshots(auth()->user());
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $stats = app(AlphaTrackerService::class)->ytdStats($user);
        $portfolioYtd = $stats['portfolio_ytd'];
        $benchmarkYtd = $stats['benchmark_ytd'];
        $alphaYtd = $stats['alpha_ytd'];
        $ticker = strtoupper((string) config('vestix.bankroll_tracker.benchmark_ticker', 'SPY'));

        return [
            $this->formatPercentStat('Jouw Rendement (YTD)', $portfolioYtd, 'heroicon-m-arrow-trending-up'),
            $this->formatPercentStat("S&P 500 ({$ticker})", $benchmarkYtd, 'heroicon-m-chart-bar'),
            $this->formatPercentStat('Jouw Alpha', $alphaYtd, 'heroicon-m-fire', accent: true),
        ];
    }

    private function formatPercentStat(string $label, ?float $value, string $icon, bool $accent = false): Stat
    {
        if ($value === null) {
            return Stat::make($label, '—')
                ->description('Nog onvoldoende data')
                ->descriptionIcon($icon)
                ->color('gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']);
        }

        $prefix = $value >= 0 ? '+' : '';
        $color = $value >= 0 ? 'success' : 'danger';
        $classes = ['vestix-stat-card', 'vestix-stat-card--dashboard'];

        if ($accent) {
            $classes[] = 'vestix-stat-card--vestix';
        }

        return Stat::make($label, $prefix.number_format($value, 1).'%')
            ->description($accent ? 'Verschil t.o.v. passief beleggen' : 'Procentuele groei sinds YTD-baseline')
            ->descriptionIcon($icon)
            ->color($color)
            ->extraAttributes(['class' => implode(' ', $classes)]);
    }
}
