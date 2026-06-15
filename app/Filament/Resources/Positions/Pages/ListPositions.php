<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Services\SquadContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
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

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ($this->activeTab ?? 'open') === 'open'
                    && (($user = auth()->user()) !== null
                        && app(SquadContext::class)->userCanInAnySquad($user, 'position.manage'))),
        ];
    }
}
