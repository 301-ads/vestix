<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
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

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => ($this->activeTab ?? 'open') === 'open'),
        ];
    }
}
