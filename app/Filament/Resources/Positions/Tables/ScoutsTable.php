<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->deferColumnManager(false)
            ->recordUrl(fn (Position $record): string => PositionResource::getUrl('edit-scout', ['record' => $record]))
            ->columns([
                TextColumn::make('ticker')
                    ->label('Ticker')
                    ->searchable()
                    ->sortable()
                    ->width('4rem'),
                TextColumn::make('entry_price')
                    ->label('Geplande entry')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->width('5.5rem'),
                TextColumn::make('new_sl')
                    ->label('Berekende SL')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->width('5.5rem'),
                TextColumn::make('planned_risk_dollars')
                    ->label('Risico ($)')
                    ->money('usd')
                    ->placeholder('—')
                    ->color('warning'),
                TextColumn::make('planned_risk_percentage')
                    ->label('Risico (%)')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->placeholder('—')
                    ->color('warning'),
                TextColumn::make('setup_grade')
                    ->label('Setup Grade')
                    ->state(fn (Position $record): ?string => self::setupGradeLabel($record))
                    ->badge()
                    ->color(fn (Position $record): string => self::setupGradeColor($record))
                    ->placeholder('—'),
                ColumnGroup::make('Schild')
                    ->columns([
                        PositionsTable::schildColumn('latest_close_price', 'Close', '7rem'),
                        PositionsTable::schildColumn('latest_sma_20', 'SMA', '7rem'),
                        PositionsTable::schildColumn('latest_atr_14', 'ATR', '6.25rem'),
                    ]),
            ])
            ->recordActions([
                PositionRecordActions::activateScout(),
                ActionGroup::make([
                    PositionRecordActions::fetchMarketData(),
                    EditAction::make()
                        ->url(fn (Position $record): string => PositionResource::getUrl('edit-scout', ['record' => $record])),
                    DeleteAction::make(),
                ])->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function setupGradeLabel(Position $record): ?string
    {
        if (
            ($record->signal_low === null && $record->latest_close_price === null)
            || $record->latest_sma_20 === null
            || $record->scout_rsi === null
        ) {
            return null;
        }

        return $record->evaluateSetupScore()['gradeLabel'];
    }

    private static function setupGradeColor(Position $record): string
    {
        if (
            ($record->signal_low === null && $record->latest_close_price === null)
            || $record->latest_sma_20 === null
            || $record->scout_rsi === null
        ) {
            return 'gray';
        }

        return match ($record->evaluateSetupScore()['grade']) {
            'A+' => 'success',
            'A-' => 'warning',
            default => 'gray',
        };
    }
}
