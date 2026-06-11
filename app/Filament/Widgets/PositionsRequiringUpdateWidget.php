<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PositionsRequiringUpdateWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $pendingCount = Position::query()->requiresSlUpdate()->count();

        return $table
            ->heading($this->buildHeading($pendingCount))
            ->searchable(false)
            ->query(fn (): Builder => Position::query()->requiresSlUpdate())
            ->columns([
                TextColumn::make('ticker')
                    ->label('Ticker'),
                TextColumn::make('current_sl')
                    ->label('Huidige SL')
                    ->money('usd'),
                TextColumn::make('new_sl')
                    ->label('Nieuwe SL')
                    ->money('usd'),
                TextColumn::make('sl_difference')
                    ->label('Verschil')
                    ->state(fn (Position $record): float => ($record->new_sl ?? 0) - (float) $record->current_sl)
                    ->money('usd')
                    ->color('success'),
            ])
            ->recordActions([
                PositionRecordActions::markAsUpdated(),
            ])
            ->emptyStateHeading('Geen acties vereist')
            ->emptyStateDescription('Alle stop-losses zijn up-to-date.')
            ->paginated(false);
    }

    private function buildHeading(int $pendingCount): string | HtmlString
    {
        if ($pendingCount === 0) {
            return 'Actie vereist';
        }

        return new HtmlString(
            'Actie vereist <span class="ml-1 inline-flex items-center rounded-md bg-warning-500/10 px-2 py-0.5 text-xs font-medium text-warning-400 ring-1 ring-inset ring-warning-500/20">'.$pendingCount.'</span>'
        );
    }
}
