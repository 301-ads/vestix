<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\PositionsTable;
use App\Filament\Widgets\ArchivePostMortemStatsWidget;
use App\Filament\Widgets\OpenPositionsStatsWidget;
use App\Services\SquadContext;
use App\Support\OpenPositionsFilters;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

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

    public function table(Table $table): Table
    {
        return $this->applyPositionFilters(PositionsTable::configure($table));
    }

    protected function makeTable(): Table
    {
        return $this->applyPositionFilters(parent::makeTable());
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

    #[On('toggle-position-focus')]
    public function togglePositionFocus(?string $focus = null): void
    {
        $current = $this->tableFilters['position_focus']['value'] ?? null;

        $this->tableFilters = $current === $focus
            ? []
            : ['position_focus' => ['value' => $focus]];

        $this->updatedTableFilters();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['@xl' => 4, '@lg' => 2, 'default' => 1])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents(
                        ($this->activeTab ?? 'open') === 'closed'
                            ? [ArchivePostMortemStatsWidget::class]
                            : [OpenPositionsStatsWidget::class],
                    ))
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

    private function applyPositionFilters(Table $table): Table
    {
        return $table
            ->deferFilters(false)
            ->filters([
                SelectFilter::make('position_focus')
                    ->label('Positie focus')
                    ->options(OpenPositionsFilters::options())
                    ->query(fn (Builder $query, array $data): Builder => OpenPositionsFilters::apply(
                        $query,
                        filled($data['value'] ?? null) ? (string) $data['value'] : null,
                    ))
                    ->indicateUsing(function (array $data): ?string {
                        $label = OpenPositionsFilters::indicatorLabel($data['value'] ?? null);

                        return $label !== null ? "Focus: {$label}" : null;
                    }),
            ]);
    }
}
