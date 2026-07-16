<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Enums\ScoutPipelineStatus;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use App\Models\User;
use App\Services\SmartAllocationService;
use App\Support\FilamentNotifier;
use App\Support\FilamentPolling;
use App\Support\PremarketGatekeeperDisplay;
use App\Support\ScoutRadarFilters;
use App\Support\SetupGradeDisplay;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class ScoutsTable
{
    /**
     * @param  class-string<resource>  $resourceClass
     */
    public static function configure(Table $table, bool $squadMode = false, string $resourceClass = ScoutResource::class): Table
    {
        $table = $table
            ->poll(FilamentPolling::INTERVAL)
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
                                WHEN buy_stop_review_required_on IS NOT NULL THEN 3
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
                    ->alignStart()
                    ->color(fn (Position $record): string => SetupGradeDisplay::color($record))
                    ->extraCellAttributes(['class' => 'vestix-setup-grade-cell'])
                    ->extraHeaderAttributes(['class' => 'vestix-setup-grade-cell'])
                    ->placeholder('—')
                    ->width('6.5rem')
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
                PositionRecordActions::promoteToA(),
                PositionRecordActions::promoteToAPlus(),
                PositionRecordActions::clearBuyStop(),
                PositionRecordActions::activateScout(),
                PositionRecordActions::rolloverBuyStop(),
                PositionRecordActions::editBuyStopEntry($resourceClass),
                PositionRecordActions::cancelBuyStopSetup(),
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
            ]);

        if (! $squadMode) {
            $table->toolbarActions([
                self::allocateBudgetBulkAction(),
            ]);
        }

        return $table;
    }

    public static function allocateBudgetBulkAction(): BulkAction
    {
        $defaultMode = (string) config('vestix.smart_sizing.default_mode', SmartAllocationService::MODE_SMART);

        return BulkAction::make('allocate_budget')
            ->label('Verdeel Budget')
            ->icon('heroicon-o-scale')
            ->color('primary')
            ->deselectRecordsAfterCompletion()
            ->modalHeading('Verdeel risicobudget')
            ->modalDescription('Verdeel je standaard risico over de geselecteerde setups.')
            ->modalSubmitActionLabel('Toepassen op scouts')
            ->modalCancelActionLabel('Annuleren')
            ->extraModalWindowAttributes(['class' => 'vestix-smart-allocation-modal'])
            ->visible(fn (): bool => auth()->user() !== null)
            ->authorize(fn (): bool => auth()->user() !== null)
            ->requiresConfirmation(false)
            ->form([
                ToggleButtons::make('mode')
                    ->label('Verdeelmethode')
                    ->options([
                        SmartAllocationService::MODE_EQUAL => 'Gelijkmatig verdelen',
                        SmartAllocationService::MODE_SMART => 'Smart Sizing',
                    ])
                    ->icons([
                        SmartAllocationService::MODE_EQUAL => 'heroicon-o-squares-2x2',
                        SmartAllocationService::MODE_SMART => 'heroicon-o-cpu-chip',
                    ])
                    ->default($defaultMode)
                    ->inline()
                    ->live()
                    ->required(),
                Placeholder::make('allocation_preview')
                    ->label('Voorbeeld')
                    ->content(function (Get $get, $livewire): HtmlString {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return new HtmlString('<p>Niet ingelogd.</p>');
                        }

                        /** @var Collection<int, Position> $records */
                        $records = $livewire->getSelectedTableRecords();

                        if ($records->count() < 2) {
                            return new HtmlString(
                                '<p class="vestix-smart-allocation__empty">Selecteer minstens 2 scouts om te verdelen.</p>'
                            );
                        }

                        $mode = (string) ($get('mode') ?: config('vestix.smart_sizing.default_mode', SmartAllocationService::MODE_SMART));
                        $result = app(SmartAllocationService::class)->allocate($user, $records, $mode);

                        return new HtmlString(
                            view('filament.positions.smart-budget-allocation', [
                                'result' => $result,
                            ])->render()
                        );
                    }),
            ])
            ->action(function (Collection $records, array $data): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                if ($records->count() < 2) {
                    FilamentNotifier::send(
                        title: 'Selecteer minstens 2 scouts',
                        body: 'Budget verdelen werkt vanaf twee setups.',
                    );

                    return;
                }

                $mode = (string) ($data['mode'] ?? SmartAllocationService::MODE_SMART);
                $service = app(SmartAllocationService::class);
                $result = $service->allocate($user, $records, $mode);

                if ($result['allocations'] === []) {
                    FilamentNotifier::send(
                        title: 'Geen allocaties',
                        body: 'Geen scout voldeed aan de criteria (score, entry/SL, bankroll).',
                    );

                    return;
                }

                $updated = $service->applyToPositions($records, $result['allocations']);

                FilamentNotifier::send(
                    title: 'Budget verdeeld',
                    body: sprintf(
                        '%d scout(s) bijgewerkt. Plaats daarna per scout je order via Order plaatsen.',
                        $updated,
                    ),
                );
            });
    }
}
