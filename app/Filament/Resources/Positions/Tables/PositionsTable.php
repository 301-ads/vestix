<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use App\Support\FilamentPolling;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\Summarizers\Average;
use Filament\Tables\Columns\Summarizers\Count;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class PositionsTable
{
    private static function blendedClosedPnlSql(): string
    {
        return 'COALESCE(realized_pnl, 0) + (exit_price - entry_price) * (quantity - COALESCE(scaled_out_quantity, 0))';
    }

    private static function blendedClosedPnlPctSql(): string
    {
        return '('.self::blendedClosedPnlSql().') / NULLIF(entry_price * quantity, 0) * 100';
    }

    private static function blendedOpenPnlSql(): string
    {
        return 'COALESCE(realized_pnl, 0) + (latest_close_price - entry_price) * (quantity - COALESCE(scaled_out_quantity, 0))';
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->poll(FilamentPolling::INTERVAL)
            ->columnManager(false)
            ->striped(false)
            ->defaultSort('unrealized_pnl_percentage', 'desc')
            ->summaries(
                pageCondition: fn (HasTable $livewire): bool => self::isArchiveTab($livewire),
                allTableCondition: fn (HasTable $livewire): bool => self::isArchiveTab($livewire),
            )
            ->columns([
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker')
                        ->searchable()
                        ->sortable()
                        ->toggleable()
                        ->width('4rem'),
                ),
                TextColumn::make('quantity')
                    ->label('Aantal')
                    ->numeric(decimalPlaces: 0, maxDecimalPlaces: 6)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()
                    ->width('4.5rem')
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire) || self::isArchiveTab($livewire))
                    ->summarize(
                        Count::make()
                            ->label('Trades')
                            ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire) && ! self::isLegacyTab($livewire)),
                    ),
                TextColumn::make('entry_price')
                    ->label(fn (HasTable $livewire): string => self::isArchiveTab($livewire) ? 'Entry Prijs' : 'Entry')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()
                    ->width('5.5rem'),
                TextColumn::make('current_sl')
                    ->label('Stop-Loss')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->tooltip('Stop-loss zoals die nu bij je broker staat.')
                    ->toggleable()
                    ->width('5.5rem')
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire)),
                TextColumn::make('actuele_koers')
                    ->label('Actuele Koers')
                    ->state(fn (Position $record): ?float => $record->latest_close_price !== null
                        ? (float) $record->latest_close_price
                        : null)
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable(query: fn ($query, string $direction) => $query->orderBy('latest_close_price', $direction))
                    ->tooltip('De live prijs (de realiteit van nu).')
                    ->toggleable()
                    ->width('6.5rem')
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire)),
                TextColumn::make('unrealized_pnl_percentage')
                    ->label(fn (HasTable $livewire): string => self::isArchiveTab($livewire) ? 'Definitieve P&L (%)' : 'P&L (%)')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->color(fn ($state) => ($state ?? 0) >= 0 ? 'success' : 'danger')
                    ->sortable(query: function ($query, string $direction): void {
                        $query->orderByRaw("CASE WHEN status = 'closed' THEN ".self::blendedClosedPnlPctSql()." ELSE ((latest_close_price - entry_price) / NULLIF(entry_price, 0)) * 100 END {$direction}");
                    })
                    ->tooltip(fn (Position $record): ?string => $record->status === 'closed'
                        ? null
                        : sprintf(
                            '%s%s',
                            $record->unrealized_pnl >= 0 ? '+' : '-',
                            '$'.number_format(abs($record->unrealized_pnl), 2),
                        ))
                    ->toggleable()
                    ->width('5rem')
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire) || self::isArchiveTab($livewire))
                    ->summarize(
                        Average::make()
                            ->label('Gem.')
                            ->suffix('%')
                            ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire))
                            ->query(fn ($query) => $query)
                            ->using(fn ($query) => $query->avg(DB::raw(self::blendedClosedPnlPctSql()))),
                    ),
                ColumnGroup::make(static::schildGroupLabel())
                    ->extraHeaderAttributes(['class' => 'vestix-schild-group-header'])
                    ->columns([
                        self::schildColumn(
                            'latest_close_price',
                            'Close',
                            '7rem',
                            'De afgesloten dagkoers (de fundering van je berekening).',
                        ),
                        self::schildColumn(
                            'latest_sma_20',
                            'SMA 20',
                            '7rem',
                            'De actuele hoogte van je trampoline.',
                        ),
                        self::schildColumn(
                            'latest_atr_14',
                            'ATR 14',
                            '6.25rem',
                            'De beweeglijkheid (zodat je stop-loss genoeg ademruimte heeft).',
                            isGroupEnd: true,
                        ),
                    ]),
                TextColumn::make('action_command')
                    ->label('Status')
                    ->width('6.5rem')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'UPDATE' => 'UPDATE',
                        'HOLD' => 'HOLD',
                        'STOPPED OUT' => 'LIQUIDATIE',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'STOPPED OUT' => 'danger',
                        'UPDATE' => 'warning',
                        'HOLD' => 'gray',
                        default => 'gray',
                    })
                    ->extraCellAttributes(fn (Position $record): array => $record->action_command === 'UPDATE'
                        ? ['class' => 'animate-pulse']
                        : [])
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire)),
                TextColumn::make('exit_price')
                    ->label('Exit Prijs')
                    ->money('usd')
                    ->sortable()
                    ->toggleable()
                    ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire)),
                TextColumn::make('unrealized_pnl')
                    ->label('Definitieve P&L ($)')
                    ->money('usd')
                    ->color(fn ($state) => ($state ?? 0) >= 0 ? 'success' : 'danger')
                    ->sortable(query: function ($query, string $direction): void {
                        $query->orderByRaw("CASE WHEN status = 'closed' THEN ".self::blendedClosedPnlSql()." ELSE ".self::blendedOpenPnlSql()." END {$direction}");
                    })
                    ->toggleable()
                    ->width('5.5rem')
                    ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire))
                    ->summarize(
                        Sum::make()
                            ->label('Netto')
                            ->money('usd')
                            ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire))
                            ->query(fn ($query) => $query)
                            ->using(fn ($query) => $query->sum(DB::raw(self::blendedClosedPnlSql()))),
                    ),
                TextColumn::make('closed_at')
                    ->label('Datum Gesloten')
                    ->dateTime()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire)),
                TextColumn::make('updated_at')
                    ->label('Bijgewerkt')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire)),
            ])
            ->recordActions([
                ActionGroup::make([
                    PositionRecordActions::scaleOut(),
                    PositionRecordActions::markInitialSlPlaced(),
                    PositionRecordActions::markAsUpdated(),
                    PositionRecordActions::shareSuccess(),
                    PositionRecordActions::fetchMarketData(),
                    PositionRecordActions::archive(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->iconButton(),
            ]);
    }

    private static function isOpenTab(HasTable $livewire): bool
    {
        if (! $livewire instanceof ListPositions) {
            return true;
        }

        return ($livewire->activeTab ?? 'open') === 'open';
    }

    private static function isArchiveTab(HasTable $livewire): bool
    {
        if (! $livewire instanceof ListPositions) {
            return false;
        }

        $tab = $livewire->activeTab ?? 'open';

        return $tab === 'closed' || $tab === 'legacy';
    }

    private static function isLegacyTab(HasTable $livewire): bool
    {
        if (! $livewire instanceof ListPositions) {
            return false;
        }

        return ($livewire->activeTab ?? 'open') === 'legacy';
    }

    public static function schildGroupLabel(): HtmlString
    {
        return new HtmlString(view('components.filament.positions.schild-group-label')->render());
    }

    public static function schildColumn(
        string $name,
        string $label,
        string $width,
        ?string $tooltip = null,
        bool $isGroupEnd = false,
    ): TextInputColumn {
        $schildClass = 'vestix-table-schild'.($isGroupEnd ? ' vestix-table-schild-end' : '');

        return TextInputColumn::make($name)
            ->label($label)
            ->type('number')
            ->inputMode('decimal')
            ->step('any')
            ->inline()
            ->disabled(fn (Position $record): bool => $record->status === 'closed')
            ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire))
            ->toggleable()
            ->width($width)
            ->when(filled($tooltip), fn (TextInputColumn $column): TextInputColumn => $column->tooltip($tooltip))
            ->extraAttributes(['style' => 'min-width:0;width:100%'])
            ->extraCellAttributes(['class' => $schildClass, 'style' => "min-width:{$width};width:{$width};padding-inline:0.5rem"])
            ->extraHeaderAttributes(['class' => $schildClass, 'style' => "min-width:{$width};width:{$width}"])
            ->rules(['nullable', 'numeric']);
    }
}
