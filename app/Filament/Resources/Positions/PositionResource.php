<?php

namespace App\Filament\Resources\Positions;

use App\Filament\Resources\Positions\Pages\CreatePosition;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Filament\Resources\Positions\Tables\PositionsTable;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $recordTitleAttribute = 'ticker';

    protected static ?string $navigationLabel = 'Posities';

    protected static ?string $modelLabel = 'positie';

    protected static ?string $pluralModelLabel = 'posities';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('status', ['open', 'closed']);

        $userId = auth()->id();

        if ($userId) {
            $query->forUser($userId);
        }

        return $query;
    }

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
            'create' => CreatePosition::route('/create'),
            'edit' => EditPosition::route('/{record}/edit'),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery()->with('asset');

        $userId = auth()->id();

        if ($userId) {
            $query->forUser($userId);
        }

        return $query;
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        /** @var Position $record */
        return new HtmlString(view('components.filament.positions.ticker-with-icon', [
            'ticker' => $record->ticker,
            'iconUrl' => $record->asset?->icon_url,
        ])->render());
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        /** @var Position $record */
        if ($record->status === 'scout') {
            return ScoutResource::getUrl('edit', ['record' => $record]);
        }

        if (static::hasPage('edit') && static::canEdit($record)) {
            return static::getUrl('edit', ['record' => $record]);
        }

        return null;
    }

    /**
     * @return string | array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): string|array
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
        $userId = auth()->id();

        return $userId
            ? (string) Position::query()->open()->forUser($userId)->count()
            : null;
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
