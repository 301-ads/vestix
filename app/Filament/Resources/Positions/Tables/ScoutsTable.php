<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Enums\ScoutPipelineStatus;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use App\Support\PremarketGatekeeperDisplay;
use App\Support\ScoutRadarFilters;
use App\Support\SetupGradeDisplay;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ScoutsTable
{
    /**
     * @param  class-string<resource>  $resourceClass
     */
    public static function configure(Table $table, bool $squadMode = false, string $resourceClass = ScoutResource::class): Table
    {
        return $table
            ->columnManager(false)
            ->striped(false)
            ->defaultSort('setup_grade', 'asc')
            ->recordUrl(fn (Position $record): ?string => $squadMode && ! $record->isOwnedBy(auth()->user())
                ? null
                : $resourceClass::getUrl('edit', ['record' => $record]))
            ->columns([
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker')
                        ->searchable()
                        ->sortable()
                        ->width('4rem')
                        ->extraCellAttributes(function (Position $record): array {
                            $class = PremarketGatekeeperDisplay::rowClass($record);

                            return $class !== null ? ['class' => $class] : [];
                        }),
                ),
                TextColumn::make('pipeline_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Position $record): ScoutPipelineStatus => $record->scoutPipelineStatus())
                    ->formatStateUsing(fn (ScoutPipelineStatus $state): string => $state->label())
                    ->color(fn (ScoutPipelineStatus $state): string => $state->badgeColor())
                    ->icon(fn (ScoutPipelineStatus $state): ?string => $state->tableIcon())
                    ->tooltip(fn (Position $record): ?string => $record->scoutPipelineStatus()->tooltip($record))
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            "CASE
                                WHEN broker_order_status = 'pending' THEN 2
                                WHEN market_open_reminder_on IS NOT NULL THEN 1
                                ELSE 0
                            END {$direction}",
                        );
                    })
                    ->width('6rem'),
                TextColumn::make('track')
                    ->label('Track')
                    ->state(fn (Position $record): ?string => ScoutRadarFilters::trackLabel($record))
                    ->badge()
                    ->color(fn (Position $record): string => ScoutRadarFilters::trackColor($record))
                    ->placeholder('—')
                    ->width('3.5rem')
                    ->visible($squadMode),
                TextColumn::make('squad.name')
                    ->label('Squad')
                    ->color('gray')
                    ->extraCellAttributes(['class' => 'vestix-squad-cell'])
                    ->visible($squadMode),
                TextColumn::make('user.name')
                    ->label('Gespot door')
                    ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString(
                        filled($state)
                            ? view('components.filament.positions.spotted-by', ['name' => $state])->render()
                            : '—',
                    ))
                    ->html()
                    ->extraCellAttributes(['class' => 'vestix-spotted-by-cell'])
                    ->visible($squadMode),
                TextColumn::make('entry_price')
                    ->label('Entry')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->width('5.5rem'),
                TextColumn::make('new_sl')
                    ->label('Stop-Loss')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->width('5.5rem'),
                TextColumn::make('planned_risk_percentage')
                    ->label('Risico (%)')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->placeholder('—')
                    ->color(fn (?float $state): string => ScoutRadarFilters::riskColor($state))
                    ->sortable()
                    ->tooltip(fn (Position $record): ?string => $record->planned_risk_dollars !== null
                        ? '$'.number_format($record->planned_risk_dollars, 2)
                        : null)
                    ->visible(! $squadMode),
                TextColumn::make('setup_grade')
                    ->label('Setup Grade')
                    ->state(fn (Position $record): ?string => SetupGradeDisplay::label($record))
                    ->badge()
                    ->color(fn (Position $record): string => SetupGradeDisplay::color($record))
                    ->extraCellAttributes(['class' => 'vestix-setup-grade-cell'])
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBySetupGrade($direction)),
                ColumnGroup::make(PositionsTable::schildGroupLabel())
                    ->extraHeaderAttributes(['class' => 'vestix-schild-group-header'])
                    ->columns([
                        PositionsTable::schildColumn('latest_close_price', 'Close', '7rem'),
                        PositionsTable::schildColumn('latest_sma_20', 'SMA', '7rem'),
                        PositionsTable::schildColumn('latest_atr_14', 'ATR', '6.25rem'),
                    ]),
            ])
            ->recordActions([
                PositionRecordActions::markBuyStopPlaced(),
                PositionRecordActions::toggleMarketOpenReminder(),
                PositionRecordActions::clearBuyStop(),
                PositionRecordActions::activateScout(),
                PositionRecordActions::cloneTarget($resourceClass),
                ActionGroup::make([
                    PositionRecordActions::shareSetup(),
                    PositionRecordActions::fetchMarketData(),
                    EditAction::make()
                        ->url(fn (Position $record): string => $resourceClass::getUrl('edit', ['record' => $record]))
                        ->visible(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false),
                    DeleteAction::make()
                        ->visible(fn (Position $record): bool => auth()->user()?->can('delete', $record) ?? false),
                ])->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(! $squadMode),
                ]),
            ]);
    }
}
