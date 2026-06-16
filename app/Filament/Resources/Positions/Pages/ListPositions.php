<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Widgets\OpenPositionsStatsWidget;
use App\Services\SquadContext;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListPositions extends ListRecords
{
    protected static string $resource = PositionResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open Posities')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'open')),
            'closed' => Tab::make('Archief')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
        ];
    }

    protected function getTableQuery(): ?Builder
    {
        $userId = auth()->id();

        return parent::getTableQuery()
            ?->when($userId, fn (Builder $query) => $query->forUser($userId))
            ->with('asset');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['@xl' => 4, '@lg' => 2, 'default' => 1])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([
                        OpenPositionsStatsWidget::class,
                    ]))
                    ->columnSpanFull(),
                $this->getTabsContentComponent(),
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
            'vestix-positions-list',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nieuwe positie')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->extraAttributes(['class' => 'vestix-btn-primary'])
                ->visible(fn (): bool => ($this->activeTab ?? 'open') === 'open'
                    && (($user = auth()->user()) !== null
                        && app(SquadContext::class)->userCanInAnySquad($user, 'position.manage'))),
        ];
    }
}
