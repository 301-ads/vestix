<?php

namespace App\Filament\Widgets;

use App\Models\VaultDeposit;
use App\Services\Kluis\VaultService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KluisStatsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Vestix Kluis';

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 3, '@lg' => 3, 'default' => 1];
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $settings = app(VaultService::class)->settingsFor($user);
        $totalEtf = (float) VaultDeposit::query()
            ->where('user_id', $user->id)
            ->sum('etf_amount');
        $months = (int) VaultDeposit::query()
            ->where('user_id', $user->id)
            ->count();
        $dryPowder = (float) $settings->dry_powder_balance;

        return [
            Stat::make('Gestort in ETF', '€'.number_format($totalEtf, 2, ',', '.'))
                ->description($months === 0 ? 'Nog geen bevestigde maanden' : "{$months} bevestigde maand(en)")
                ->descriptionIcon('heroicon-m-building-library')
                ->color('primary')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
            Stat::make('Droog kruit', '€'.number_format($dryPowder, 2, ',', '.'))
                ->description('Cash-reserve langs de zijlijn')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
            Stat::make('Kern-ETF', strtoupper((string) $settings->etf_ticker))
                ->description('Thermometer-benchmark van de kluis')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--dashboard']),
        ];
    }
}
