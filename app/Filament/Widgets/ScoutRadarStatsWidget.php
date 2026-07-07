<?php

namespace App\Filament\Widgets;

use App\Enums\ScoutPipelineStatus;
use App\Models\Position;
use App\Support\FilamentPolling;
use App\Support\ScoutRadarFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Reactive;

class ScoutRadarStatsWidget extends StatsOverviewWidget
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

        $scouts = Position::scout()->forUser($userId)->get();

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

        $premarketCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'premarket_signals'),
        )->count();

        $pendingCount = $scouts->filter(
            fn (Position $scout): bool => $scout->scoutPipelineStatus() === ScoutPipelineStatus::Pending,
        )->count();

        $activeCount = $scouts->filter(
            fn (Position $scout): bool => $scout->scoutPipelineStatus() === ScoutPipelineStatus::Active,
        )->count();

        $executionCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'execution_pipeline'),
        )->count();

        $aPlusCount = $scouts->filter(
            fn (Position $scout): bool => ScoutRadarFilters::matches($scout, 'a_plus'),
        )->count();

        return [
            Stat::make('Klaar voor Executie', (string) $readyCount)
                ->description('Binnen 1% van entry · min. B setup')
                ->descriptionIcon('heroicon-m-bolt')
                ->descriptionColor('gray')
                ->extraAttributes($this->filterableStatAttributes('ready', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--zinc')),
            Stat::make('Pre-market scan', (string) $premarketCount)
                ->description(self::premarketDescription($gapUpCount, $reclamationCount, $landingCount))
                ->descriptionIcon('heroicon-m-sun')
                ->descriptionColor(self::premarketColor($gapUpCount, $reclamationCount, $landingCount))
                ->extraAttributes($this->filterableStatAttributes('premarket_signals', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--vestix')),
            Stat::make('Executie', (string) $executionCount)
                ->description(self::executionDescription($activeCount, $pendingCount))
                ->descriptionIcon('heroicon-m-clock')
                ->descriptionColor(self::executionColor($activeCount, $pendingCount))
                ->extraAttributes($this->filterableStatAttributes('execution_pipeline', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--amber')),
            Stat::make('Top Setups (A+)', (string) $aPlusCount)
                ->description('Hoogste succesratio')
                ->descriptionIcon('heroicon-m-star')
                ->descriptionColor('info')
                ->extraAttributes($this->filterableStatAttributes('a_plus', 'vestix-stat-card vestix-stat-card--uppercase-label vestix-stat-card--blue')),
        ];
    }

    private static function premarketDescription(int $gapUp, int $reclamation, int $landing): string
    {
        return sprintf('%d gap · %d recl. · %d landing', $gapUp, $reclamation, $landing);
    }

    private static function executionDescription(int $active, int $pending): string
    {
        return sprintf('%d active · %d pending', $active, $pending);
    }

    private static function premarketColor(int $gapUp, int $reclamation, int $landing): string
    {
        return match (true) {
            $gapUp > 0 => 'danger',
            $landing > 0 => 'warning',
            $reclamation > 0 => 'success',
            default => 'gray',
        };
    }

    private static function executionColor(int $active, int $pending): string
    {
        return match (true) {
            $active > 0 => 'warning',
            $pending > 0 => 'info',
            default => 'gray',
        };
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
