<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\Tables\ScoutsTable;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Widgets\ScoutRadarStatsWidget;
use App\Support\ScoutRadarFilters;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ListScouts extends ListRecords
{
    protected static string $resource = ScoutResource::class;

    protected static ?string $title = 'Mijn Radar';

    protected static ?string $breadcrumb = 'Mijn Radar';

    public function table(Table $table): Table
    {
        return $this->configureRadarTable($table);
    }

    protected function makeTable(): Table
    {
        return $this->configureRadarTable(
            Table::make($this)
                ->query(fn () => $this->getTableQuery()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'tableFilters' => $this->tableFilters,
        ];
    }

    #[On('toggle-radar-focus')]
    public function toggleRadarFocus(?string $focus = null): void
    {
        $current = $this->tableFilters['radar_focus']['value'] ?? null;

        $this->tableFilters = $current === $focus
            ? []
            : ['radar_focus' => ['value' => $focus]];

        $this->updatedTableFilters();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['@xl' => 4, '@lg' => 2, 'default' => 1])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                        ScoutRadarStatsWidget::class,
                    ]))
                    ->columnSpanFull(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            'fi-resource-list-records-page',
            'fi-resource-'.str_replace('/', '-', static::getResource()::getSlug(Filament::getCurrentOrDefaultPanel())),
            'vestix-radar-list',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make('createScout')
                ->label('Scout toevoegen')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->extraAttributes(['class' => 'vestix-btn-primary'])
                ->url(ScoutResource::getUrl('create'))
                ->visible(fn (): bool => ScoutResource::canCreate()),
        ];
    }

    private function configureRadarTable(Table $table): Table
    {
        return ScoutsTable::configure(
            $table,
            squadMode: false,
            resourceClass: ScoutResource::class,
        )
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('radar_focus')
                    ->label('Radar focus')
                    ->options(ScoutRadarFilters::options())
                    ->query(fn (Builder $query, array $data): Builder => ScoutRadarFilters::apply(
                        $query,
                        filled($data['value'] ?? null) ? (string) $data['value'] : null,
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        $label = ScoutRadarFilters::indicatorLabel($data['value'] ?? null);

                        return $label !== null ? "Focus: {$label}" : null;
                    }),
            ]);
    }
}
