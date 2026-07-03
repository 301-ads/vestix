<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use App\Support\FilamentPolling;
use App\Support\OpenPositionsFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Livewire\Attributes\Reactive;

class OpenPositionsStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = FilamentPolling::INTERVAL;

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>|null
     */
    #[Reactive]
    public ?array $tableFilters = null;

    protected function getColumns(): int|array|null
    {
        return ['@xl' => 4, '@lg' => 2, 'default' => 1];
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

        $openPositions = Position::open()->forUser($userId)->get();

        if ($openPositions->isEmpty()) {
            return [
                Stat::make('Totaal Open Risico', '$0.00')
                    ->description('Geen open posities')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->descriptionColor('warning')
                    ->extraAttributes($this->filterableStatAttributes('at_risk', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber')),
                Stat::make('Veiliggestelde Winst', '$0.00')
                    ->description('Risicovrij via Stop-Loss')
                    ->descriptionIcon('heroicon-m-shield-check')
                    ->descriptionColor('info')
                    ->extraAttributes($this->filterableStatAttributes('secured_profit', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue')),
                Stat::make('Winst/Verlies Ratio', '0 / 0')
                    ->description('Geen open posities')
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->descriptionColor('success')
                    ->extraAttributes($this->filterableStatAttributes('winners', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix')),
                Stat::make('In Gevarenzone (< 2%)', '0')
                    ->description('Geen posities in gevarenzone')
                    ->descriptionIcon('heroicon-m-information-circle')
                    ->descriptionColor('gray')
                    ->extraAttributes($this->filterableStatAttributes('danger_zone', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--rose')),
            ];
        }

        $totalRisk = $openPositions->sum(fn (Position $position) => $position->capital_risk_dollars);
        $totalValue = $openPositions->sum(fn (Position $position) => $position->current_value);
        $riskPct = $totalValue > 0 ? ($totalRisk / $totalValue) * 100 : 0;
        $lockedProfit = $openPositions->sum(fn (Position $position) => $position->locked_in_profit_dollars);
        $winners = $openPositions->filter(fn (Position $position) => $position->unrealized_pnl >= 0)->count();
        $losers = $openPositions->filter(fn (Position $position) => $position->unrealized_pnl < 0)->count();
        $winRate = $openPositions->count() > 0 ? ($winners / $openPositions->count()) * 100 : 0;

        $securedCount = $openPositions->filter(
            fn (Position $position): bool => OpenPositionsFilters::matches($position, 'secured_profit'),
        )->count();
        $dangerPositions = $openPositions->filter(
            fn (Position $position): bool => OpenPositionsFilters::matches($position, 'danger_zone'),
        );
        $dangerCount = $dangerPositions->count();

        return [
            Stat::make('Totaal Open Risico', '$'.number_format($totalRisk, 2))
                ->description(sprintf('Slechts %.2f%% van portfolio', $riskPct))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->descriptionColor('warning')
                ->extraAttributes($this->filterableStatAttributes('at_risk', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber')),
            Stat::make('Veiliggestelde Winst', '+$'.number_format($lockedProfit, 2))
                ->description($securedCount > 0
                    ? sprintf('%d %s risicovrij', $securedCount, $securedCount === 1 ? 'positie' : 'posities')
                    : 'Risicovrij via Stop-Loss')
                ->descriptionIcon('heroicon-m-shield-check')
                ->descriptionColor('info')
                ->extraAttributes($this->filterableStatAttributes('secured_profit', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue')),
            Stat::make('Winst/Verlies Ratio', $winners.' / '.$losers)
                ->description(number_format($winRate, 0).'% Win rate (Open)')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->descriptionColor('success')
                ->extraAttributes($this->filterableStatAttributes('winners', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix')),
            Stat::make('In Gevarenzone (< 2%)', (string) $dangerCount)
                ->description(self::dangerZoneDescription($dangerPositions))
                ->descriptionIcon('heroicon-m-information-circle')
                ->descriptionColor($dangerCount > 0 ? 'danger' : 'gray')
                ->extraAttributes($this->filterableStatAttributes('danger_zone', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--rose')),
        ];
    }

    /**
     * @param  Collection<int, Position>  $dangerPositions
     */
    private static function dangerZoneDescription($dangerPositions): string
    {
        if ($dangerPositions->isEmpty()) {
            return 'Geen posities in gevarenzone';
        }

        $tickers = $dangerPositions->sortBy('ticker')->pluck('ticker')->all();
        $tickerList = implode(', ', $tickers);
        $verb = count($tickers) === 1 ? 'kruipt' : 'kruipen';

        return "{$tickerList} {$verb} naar stop-loss";
    }

    /**
     * @return array<string, string>
     */
    private function filterableStatAttributes(string $focus, string $baseClasses): array
    {
        $isActive = ($this->tableFilters['position_focus']['value'] ?? null) === $focus;

        return [
            'class' => trim($baseClasses.' vestix-stat-card--filterable'.($isActive ? ' vestix-stat-card--active' : '')),
            'wire:click' => "\$dispatch('toggle-position-focus', { focus: '{$focus}' })",
            'role' => 'button',
            'tabindex' => '0',
        ];
    }
}
