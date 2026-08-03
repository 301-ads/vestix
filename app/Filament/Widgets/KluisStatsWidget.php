<?php

namespace App\Filament\Widgets;

use App\Services\Kluis\VaultService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KluisStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Vestix Kluis';

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 4, '@lg' => 2, 'default' => 1];
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $vault = app(VaultService::class);
        $settings = $vault->settingsFor($user);
        $summary = $vault->holdingsSummary($user);
        $ticker = strtoupper((string) $settings->etf_ticker);

        $holdingsLabel = $summary->hasLivePrice()
            ? '€'.number_format((float) $summary->holdingsValue, 2, ',', '.')
            : '€'.number_format($summary->costBasis, 2, ',', '.').'*';

        $holdingsDescription = $summary->hasLivePrice()
            ? number_format($summary->shares, 4, ',', '.')." {$ticker} · €".number_format((float) $summary->livePrice, 2, ',', '.')
                .($summary->priceSymbol ? " ({$summary->priceSymbol})" : '')
            : ($summary->transactionCount === 0
                ? 'Nog geen aankopen — ververs thermometer voor live koers'
                : 'Cost basis · ververs thermometer voor live EUR-waarde');

        $pnl = $summary->unrealizedPnl;
        $pnlLabel = $pnl === null
            ? '—'
            : (($pnl >= 0 ? '+' : '−').'€'.number_format(abs($pnl), 2, ',', '.'));

        return [
            Stat::make('Holdings-waarde', $holdingsLabel)
                ->description($holdingsDescription)
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
            Stat::make('Cost basis', '€'.number_format($summary->costBasis, 2, ',', '.'))
                ->description(
                    $summary->transactionCount === 0
                        ? 'Nog geen aankopen'
                        : "{$summary->transactionCount} aankoop(en) · fees €".number_format($summary->fees, 2, ',', '.')
                )
                ->descriptionIcon('heroicon-m-building-library')
                ->color('gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
            Stat::make('Ongerealiseerde P&L', $pnlLabel)
                ->description($summary->hasLivePrice() ? 'Holdings − cost basis' : 'Wacht op live koers')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color($pnl === null ? 'gray' : ($pnl >= 0 ? 'success' : 'danger'))
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
            Stat::make('Totaal strategisch', '€'.number_format((float) $summary->totalStrategic, 2, ',', '.'))
                ->description('Holdings/cost + droog kruit €'.number_format($summary->dryPowder, 2, ',', '.'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
        ];
    }
}
