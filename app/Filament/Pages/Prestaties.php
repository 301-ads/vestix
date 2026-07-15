<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AlphaTrackerChart;
use App\Filament\Widgets\AlphaTrackerStatsWidget;
use App\Filament\Widgets\PerformanceComingSoonWidget;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Prestaties extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Prestaties';

    protected static ?string $title = 'Prestaties';

    protected static ?string $slug = 'prestaties';

    protected static ?int $navigationSort = 5;

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
            AlphaTrackerStatsWidget::class,
            AlphaTrackerChart::class,
            PerformanceComingSoonWidget::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make($this->getColumns())
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->getWidgets())),
            ]);
    }
}
