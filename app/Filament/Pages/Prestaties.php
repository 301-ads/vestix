<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AlphaTrackerChart;
use App\Filament\Widgets\AlphaTrackerStatsWidget;
use App\Filament\Widgets\DirectionPnlSplitWidget;
use App\Filament\Widgets\KluisComingSoonWidget;
use App\Filament\Widgets\KluisStatsWidget;
use App\Filament\Widgets\PerformanceComingSoonWidget;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Prestaties extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $navigationLabel = 'Prestaties';

    protected static ?string $title = 'Prestaties';

    protected static ?string $slug = 'prestaties';

    protected static string|\UnitEnum|null $navigationGroup = 'Tactisch';

    protected static ?int $navigationSort = 5;

    public function getColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<class-string>
     */
    public function getSwingWidgets(): array
    {
        return [
            AlphaTrackerStatsWidget::class,
            DirectionPnlSplitWidget::class,
            AlphaTrackerChart::class,
            PerformanceComingSoonWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getKluisWidgets(): array
    {
        return [
            KluisStatsWidget::class,
            KluisComingSoonWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            ...$this->getSwingWidgets(),
            ...$this->getKluisWidgets(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString('tab')
                    ->tabs([
                        Tab::make('Swing Sniper')
                            ->icon(Heroicon::OutlinedViewfinderCircle)
                            ->schema([
                                Grid::make($this->getColumns())
                                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getSwingWidgets())),
                            ]),
                        Tab::make('Vestix Kluis')
                            ->icon(Heroicon::OutlinedBuildingLibrary)
                            ->schema([
                                Grid::make($this->getColumns())
                                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getKluisWidgets())),
                            ]),
                    ]),
            ]);
    }
}
