<?php

namespace App\Filament\Resources\SquadRadar\Pages;

use App\Filament\Resources\SquadRadar\SquadRadarResource;
use App\Models\Squad;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListSquadRadar extends ListRecords
{
    protected static string $resource = SquadRadarResource::class;

    protected static ?string $title = 'Gedeelde Radar';

    protected static ?string $breadcrumb = 'Gedeelde Radar';

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->filters([
                SelectFilter::make('squad_id')
                    ->label('Squad')
                    ->options(fn (): array => auth()->user()
                        ?->squads()
                        ->orderBy('squads.name')
                        ->pluck('squads.name', 'squads.id')
                        ->all() ?? [])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('squad_id', $data['value'])
                        : $query)
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['value'] ?? null)) {
                            return null;
                        }

                        $name = Squad::query()->whereKey($data['value'])->value('name');

                        return $name ? "Squad: {$name}" : null;
                    }),
            ]);
    }
}
