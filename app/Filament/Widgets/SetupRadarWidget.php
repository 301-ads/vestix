<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class SetupRadarWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->columnManager(false)
            ->striped(false)
            ->heading('Setup Radar')
            ->searchable(false)
            ->query(fn (): Builder => Position::scout()
                ->forUser(auth()->id())
                ->with('asset')
                ->latest())
            ->recordUrl(fn (Position $record): string => ScoutResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker'),
                ),
                TextColumn::make('latest_close_price')
                    ->label('Close')
                    ->money('usd')
                    ->placeholder('—'),
                TextColumn::make('new_sl')
                    ->label('Berekende SL')
                    ->money('usd')
                    ->placeholder('—'),
                TextColumn::make('entry_price')
                    ->label('Geplande entry')
                    ->money('usd')
                    ->placeholder('—'),
                TextColumn::make('planned_risk_percentage')
                    ->label('Risico (%)')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->placeholder('—')
                    ->color('warning'),
            ])
            ->recordActions([
                PositionRecordActions::activateScout(),
                PositionRecordActions::shareSetup(),
                ActionGroup::make([
                    PositionRecordActions::fetchMarketData(),
                ])->iconButton(),
            ])
            ->emptyStateHeading('Geen scouts in de watchlist')
            ->emptyStateDescription('Voeg A+ setups toe via Mijn Radar in de sidebar.')
            ->paginated(false);
    }
}
