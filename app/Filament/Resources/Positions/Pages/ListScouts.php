<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\Tables\ScoutsTable;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Widgets\ScoutRadarStatsWidget;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;

class ListScouts extends ListRecords
{
    protected static string $resource = ScoutResource::class;

    protected static ?string $title = 'Mijn Radar';

    protected static ?string $breadcrumb = 'Mijn Radar';

    public function table(Table $table): Table
    {
        return ScoutsTable::configure(
            $table,
            squadMode: false,
            resourceClass: ScoutResource::class,
        );
    }

    protected function makeTable(): Table
    {
        return ScoutsTable::configure(
            Table::make($this)
                ->query(fn () => $this->getTableQuery()),
            squadMode: false,
            resourceClass: ScoutResource::class,
        );
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
                ->color('success')
                ->url(ScoutResource::getUrl('create'))
                ->extraAttributes(['class' => 'vestix-btn-primary'])
                ->visible(fn (): bool => ScoutResource::canCreate()),
        ];
    }
}
