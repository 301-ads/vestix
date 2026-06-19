<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EquityCurveChart;
use App\Filament\Widgets\StrategyCoachStatsWidget;
use App\Filament\Widgets\TagPerformanceChart;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class StrategyCoach extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Strategy Coach';

    protected static ?string $title = 'Strategy Coach';

    protected static ?string $slug = 'strategy-coach';

    protected static ?int $navigationSort = 4;

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
            StrategyCoachStatsWidget::class,
            EquityCurveChart::class,
            TagPerformanceChart::class,
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
