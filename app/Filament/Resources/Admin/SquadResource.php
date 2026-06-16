<?php

namespace App\Filament\Resources\Admin;

use App\Filament\Pages\ManageSquadSettings;
use App\Filament\Resources\Admin\SquadResource\Pages\ListSquads;
use App\Models\Squad;
use App\Services\SquadManagementService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SquadResource extends Resource
{
    protected static ?string $model = Squad::class;

    protected static ?string $navigationLabel = 'Squads';

    protected static ?string $modelLabel = 'squad';

    protected static ?string $pluralModelLabel = 'squads';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Beheer';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('owner')
            ->withCount('users');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Eigenaar')
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Leden')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view_settings')
                    ->label('Bekijken')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Squad $record): string => ManageSquadSettings::getUrl(['squad' => $record->id])),
                DeleteAction::make()
                    ->modalHeading('Squad verwijderen')
                    ->modalDescription('Alle gedeelde radar-setups worden privé. Leden verliezen toegang tot deze squad. Dit kan niet ongedaan worden gemaakt.')
                    ->action(fn (Squad $record) => app(SquadManagementService::class)->delete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Squads verwijderen')
                        ->modalDescription('De geselecteerde squads en hun gedeelde setups worden permanent verwijderd.')
                        ->action(function (Collection $records): void {
                            $service = app(SquadManagementService::class);

                            foreach ($records as $record) {
                                if ($record instanceof Squad) {
                                    $service->delete($record);
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSquads::route('/'),
        ];
    }
}
