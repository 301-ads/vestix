<?php

namespace App\Filament\Resources\Admin;

use App\Filament\Resources\Admin\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\UserDeletionService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Gebruikers';

    protected static ?string $modelLabel = 'gebruiker';

    protected static ?string $pluralModelLabel = 'gebruikers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Beheer';

    protected static ?int $navigationSort = 2;

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
        $actor = auth()->user();

        if (! $actor instanceof User || ! $record instanceof User) {
            return false;
        }

        return app(UserDeletionService::class)->canDelete($actor, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('squads');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('squads_count')
                    ->label('Squads')
                    ->sortable(),
                IconColumn::make('is_super_admin')
                    ->label('Super admin')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Gebruiker verwijderen')
                    ->modalDescription('Het account en alle persoonlijke posities worden permanent verwijderd. Squad-lidmaatschappen worden opgeheven; squads waar deze gebruiker eigenaar van is worden ook verwijderd.')
                    ->action(fn (User $record) => app(UserDeletionService::class)->delete($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('Gebruikers verwijderen')
                        ->modalDescription('De geselecteerde accounts en hun persoonlijke data worden permanent verwijderd.')
                        ->action(function (Collection $records): void {
                            $service = app(UserDeletionService::class);

                            foreach ($records as $record) {
                                if ($record instanceof User) {
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
            'index' => ListUsers::route('/'),
        ];
    }
}
