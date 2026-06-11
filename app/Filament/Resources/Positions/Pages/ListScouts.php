<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\ScoutsTable;
use App\Models\Position;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListScouts extends ListRecords
{
    protected static string $resource = PositionResource::class;

    protected static ?string $title = 'Setup Radar';

    protected static ?string $breadcrumb = 'Setup Radar';

    public function table(Table $table): Table
    {
        return ScoutsTable::configure($table);
    }

    protected function makeTable(): Table
    {
        return ScoutsTable::configure(
            Table::make($this)
                ->query(fn (): Builder => $this->getTableQuery()),
        );
    }

    protected function getTableQuery(): ?Builder
    {
        return Position::query()->where('status', 'scout');
    }

    public function getTabs(): array
    {
        return [];
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
                ->url(PositionResource::getUrl('create-scout')),
        ];
    }
}
