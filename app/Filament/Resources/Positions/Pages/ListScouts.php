<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\Tables\ScoutsTable;
use App\Filament\Resources\Scouts\ScoutResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
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
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make('createScout')
                ->label('Scout toevoegen')
                ->url(ScoutResource::getUrl('create'))
                ->visible(fn (): bool => ScoutResource::canCreate()),
        ];
    }
}
