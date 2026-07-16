<?php

namespace App\Filament\Resources\Scouts;

use App\Filament\Resources\Positions\Pages\CreateScout;
use App\Filament\Resources\Positions\Pages\EditScout;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Filament\Resources\Positions\Tables\ScoutsTable;
use App\Models\Position;
use App\Services\SquadContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScoutResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $recordTitleAttribute = 'ticker';

    protected static ?string $navigationLabel = 'Mijn Radar';

    protected static ?string $modelLabel = 'scout';

    protected static ?string $pluralModelLabel = 'scouts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewfinderCircle;

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return PositionForm::configure($schema, scoutMode: true);
    }

    public static function table(Table $table): Table
    {
        return ScoutsTable::configure($table, squadMode: false, resourceClass: static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->scout()->nonLegacy()->with(['asset', 'user']);

        $userId = auth()->id();

        if ($userId) {
            $query->forUser($userId);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScouts::route('/'),
            'create' => CreateScout::route('/create'),
            'edit' => EditScout::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return app(SquadContext::class)->userCanInAnySquad($user, 'scout.create');
    }

    public static function getNavigationBadge(): ?string
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        $reviewCount = Position::query()
            ->scout()
            ->nonLegacy()
            ->forUser($userId)
            ->requiringBuyStopReview()
            ->count();

        if ($reviewCount > 0) {
            return (string) $reviewCount;
        }

        return (string) Position::query()->scout()->nonLegacy()->forUser($userId)->count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        $userId = auth()->id();

        if ($userId === null) {
            return 'Scouts in watchlist';
        }

        $reviewCount = Position::query()
            ->scout()
            ->nonLegacy()
            ->forUser($userId)
            ->requiringBuyStopReview()
            ->count();

        if ($reviewCount > 0) {
            return $reviewCount === 1
                ? '1 buy-stop wacht op review'
                : "{$reviewCount} buy-stops wachten op review";
        }

        return 'Scouts in watchlist';
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        $reviewCount = Position::query()
            ->scout()
            ->nonLegacy()
            ->forUser($userId)
            ->requiringBuyStopReview()
            ->count();

        return $reviewCount > 0 ? 'warning' : null;
    }
}
