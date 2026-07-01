<?php

namespace App\Filament\Widgets;

use App\Enums\BrokerOrderStatus;
use App\Models\Position;
use App\Support\ScoutRadarFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

class ScoutRadarStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>|null
     */
    #[Reactive]
    public ?array $tableFilters = null;

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 4, '@lg' => 3, 'default' => 2];
    }

    public function updatedTableFilters(): void
    {
        $this->cachedStats = null;
    }

    protected function getStats(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        $scouts = Position::scout()->forUser($userId)->get();
        $activeCount = $scouts->count();

        $aPlusCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'a_plus'),
        )->count();

        $riskPercentages = $scouts
            ->map(fn (Position $scout) => $scout->planned_risk_percentage)
            ->filter(fn (?float $value): bool => $value !== null);

        $avgRisk = $riskPercentages->isNotEmpty()
            ? $riskPercentages->avg()
            : 0;

        $readyCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'ready'),
        )->count();

        $gapUpCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'gap_up'),
        )->count();

        $reclamationCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'reclamation'),
        )->count();

        $landingCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'landing'),
        )->count();

        $pendingCount = $scouts->filter(
            fn (Position $scout): bool => $scout->broker_order_status === BrokerOrderStatus::Pending,
        )->count();

        $reminderCount = $scouts->filter(
            fn (Position $scout): bool => $scout->market_open_reminder_on !== null,
        )->count();

        return [
            Stat::make('Actieve Scouts', (string) $activeCount)
                ->description('Op je watchlist')
                ->descriptionIcon('heroicon-m-eye')
                ->descriptionColor('success')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix']),
            Stat::make('Top Setups (A+)', (string) $aPlusCount)
                ->description('Hoogste succesratio')
                ->descriptionIcon('heroicon-m-star')
                ->descriptionColor('info')
                ->extraAttributes($this->filterableStatAttributes('a_plus', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue')),
            Stat::make('Gem. Gepland Risico', number_format($avgRisk, 2).'%')
                ->description('Per transactie')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor('warning')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber']),
            Stat::make('Klaar voor Executie', (string) $readyCount)
                ->description('Binnen 1% van entry')
                ->descriptionIcon('heroicon-m-bolt')
                ->descriptionColor('gray')
                ->extraAttributes($this->filterableStatAttributes('ready', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--zinc')),
            Stat::make('Orders Live', (string) $pendingCount)
                ->description('Buy-stop geplaatst bij broker')
                ->descriptionIcon('heroicon-m-clock')
                ->descriptionColor($pendingCount > 0 ? 'warning' : 'gray')
                ->extraAttributes($this->filterableStatAttributes('pending_only', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber')),
            Stat::make('Reminder Gepland', (string) $reminderCount)
                ->description('Market open Telegram (15:35)')
                ->descriptionIcon('heroicon-m-bell-alert')
                ->descriptionColor($reminderCount > 0 ? 'info' : 'gray')
                ->extraAttributes(['class' => 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue']),
            Stat::make('Gap-up Risico', (string) $gapUpCount)
                ->description('Boven bounce high (14:30)')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor($gapUpCount > 0 ? 'danger' : 'success')
                ->extraAttributes($this->filterableStatAttributes('gap_up', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix')),
            Stat::make('Reclamation PM', (string) $reclamationCount)
                ->description('Herovert SMA 20 pre-market')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->descriptionColor($reclamationCount > 0 ? 'success' : 'gray')
                ->extraAttributes($this->filterableStatAttributes('reclamation', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--green')),
            Stat::make('Landing PM', (string) $landingCount)
                ->description('Nadert SMA 20 pre-market')
                ->descriptionIcon('heroicon-m-map-pin')
                ->descriptionColor($landingCount > 0 ? 'warning' : 'gray')
                ->extraAttributes($this->filterableStatAttributes('landing', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function filterableStatAttributes(string $focus, string $baseClasses): array
    {
        $isActive = ($this->tableFilters['radar_focus']['value'] ?? null) === $focus;

        return [
            'class' => trim($baseClasses.' vestix-stat-card--filterable'.($isActive ? ' vestix-stat-card--active' : '')),
            'wire:click' => "\$dispatch('toggle-radar-focus', { focus: '{$focus}' })",
            'role' => 'button',
            'tabindex' => '0',
        ];
    }
}
