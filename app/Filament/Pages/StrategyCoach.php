<?php

namespace App\Filament\Pages;

use App\Enums\TradeDirection;
use App\Filament\Widgets\EquityCurveChart;
use App\Filament\Widgets\PortfolioCoachInsightsWidget;
use App\Filament\Widgets\StrategyCoachStatsWidget;
use App\Support\StrategyCoachDemoPreview;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class StrategyCoach extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Vestix Coach';

    protected static ?string $title = 'Vestix Coach';

    protected static ?string $slug = 'strategy-coach';

    protected static ?int $navigationSort = 4;

    #[Url]
    public string $directionFilter = 'all';

    public function setDirectionFilter(string $filter): void
    {
        $this->directionFilter = match ($filter) {
            TradeDirection::Long->value, TradeDirection::Short->value => $filter,
            default => 'all',
        };

        session(['vestix.coach_direction_filter' => $this->directionFilter]);
        $this->dispatch('coach-direction-updated', filter: $this->directionFilter);
    }

    public function mount(): void
    {
        if (! in_array($this->directionFilter, ['all', TradeDirection::Long->value, TradeDirection::Short->value], true)) {
            $this->directionFilter = 'all';
        }

        session(['vestix.coach_direction_filter' => $this->directionFilter]);
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            PortfolioCoachInsightsWidget::class,
            StrategyCoachStatsWidget::class,
            EquityCurveChart::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make($this->getColumns())
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                        PortfolioCoachInsightsWidget::class,
                    ])),
                Section::make('Edge-analyse')
                    ->description(StrategyCoachDemoPreview::enabled()
                        ? 'Historische edge op gesloten trades · lokale demo-data'
                        : 'Historische edge op gesloten trades')
                    ->dense()
                    ->schema([
                        View::make('filament.pages.strategy-coach-direction-filter')
                            ->viewData(fn (): array => [
                                'directionFilter' => $this->directionFilter,
                            ]),
                        Grid::make($this->getColumns())
                            ->dense()
                            ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                                StrategyCoachStatsWidget::class,
                                EquityCurveChart::class,
                            ])),
                    ])
                    ->extraAttributes(['class' => 'vestix-coach-edge-section']),
            ]);
    }
}
