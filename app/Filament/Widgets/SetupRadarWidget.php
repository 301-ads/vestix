<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
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

    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Setup Radar')
            ->searchable(false)
            ->query(fn (): Builder => Position::scout()->latest())
            ->recordUrl(fn (Position $record): string => PositionResource::getUrl('edit-scout', ['record' => $record]))
            ->columns([
                TextColumn::make('ticker')
                    ->label('Ticker'),
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
                ActionGroup::make([
                    PositionRecordActions::fetchMarketData(),
                ])->iconButton(),
            ])
            ->emptyStateHeading('Geen scouts in de watchlist')
            ->emptyStateDescription('Voeg A+ setups toe via Setup Radar in de sidebar.')
            ->paginated(false);
    }
}
