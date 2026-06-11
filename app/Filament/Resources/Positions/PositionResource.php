<?php

namespace App\Filament\Resources\Positions;

use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\CreateScout;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\EditScout;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Filament\Resources\Positions\Tables\PositionsTable;
use App\Models\Position;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $recordTitleAttribute = 'ticker';

    protected static ?string $navigationLabel = 'Posities';

    protected static ?string $modelLabel = 'positie';

    protected static ?string $pluralModelLabel = 'posities';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    public static function form(Schema $schema): Schema
    {
        return PositionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PositionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPositions::route('/'),
            'scouts' => ListScouts::route('/setup-radar'),
            'create' => CreatePosition::route('/create'),
            'create-scout' => CreateScout::route('/create-scout'),
            'edit-scout' => EditScout::route('/setup-radar/{record}/edit'),
            'edit' => EditPosition::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        /** @var Position $record */
        if ($record->status === 'scout' && static::hasPage('edit-scout') && static::canEdit($record)) {
            return static::getUrl('edit-scout', ['record' => $record]);
        }

        if (static::hasPage('edit') && static::canEdit($record)) {
            return static::getUrl('edit', ['record' => $record]);
        }

        return null;
    }

    /**
     * @return string | array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): string | array
    {
        $base = static::getRouteBaseName();

        return [
            "{$base}.index",
            "{$base}.create",
            "{$base}.edit",
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) Position::query()->open()->count();
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Open posities';
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['ticker', 'trade_journal'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Position $record */
        $pnl = $record->unrealized_pnl;
        $pnlFormatted = ($pnl >= 0 ? '+' : '').'$'.number_format($pnl, 2);

        $statusLabel = match ($record->status) {
            'scout' => 'Scout',
            'open' => 'Open',
            default => 'Gesloten',
        };

        return [
            'Status' => $statusLabel,
            'P&L' => $pnlFormatted,
        ];
    }
}
