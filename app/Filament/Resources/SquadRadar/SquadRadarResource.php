<?php

namespace App\Filament\Resources\SquadRadar;

use App\Enums\PositionVisibility;
use App\Filament\Resources\Positions\Tables\ScoutsTable;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Resources\SquadRadar\Pages\ListSquadRadar;
use App\Models\Position;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SquadRadarResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $navigationLabel = 'Gedeelde Radar';

    protected static ?string $modelLabel = 'gedeelde setup';

    protected static ?string $pluralModelLabel = 'gedeelde setups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Squads';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return ScoutsTable::configure($table, squadMode: true, resourceClass: ScoutResource::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        $query = parent::getEloquentQuery()
            ->scout()
            ->where('visibility', PositionVisibility::Squad)
            ->with(['asset', 'user', 'squad']);

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        $squadIds = $user->squads()->pluck('squads.id');

        return $query->whereIn('squad_id', $squadIds);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSquadRadar::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
