<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Filament\Resources\Positions\Pages\ListPositions;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PositionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columnManager(false)
            ->striped(false)
            ->defaultSort('unrealized_pnl_percentage', 'desc')
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
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire) || self::isArchiveTab($livewire)),
                TextColumn::make('entry_price')
                    ->label(fn (HasTable $livewire): string => self::isArchiveTab($livewire) ? 'Entry Prijs' : 'Entry')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()
                    ->width('5.5rem'),
                TextColumn::make('new_sl')
                    ->label('Stop-Loss')
                    ->money('usd')
                    ->placeholder('—')
                    ->sortable()
                    ->tooltip('Berekende stop-loss (SMA20 − 0,5 × ATR14).')
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
                        $query->orderByRaw("CASE WHEN status = 'closed' THEN ((exit_price - entry_price) / NULLIF(entry_price, 0)) * 100 ELSE ((latest_close_price - entry_price) / NULLIF(entry_price, 0)) * 100 END {$direction}");
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
                    ->visible(fn (HasTable $livewire): bool => self::isOpenTab($livewire) || self::isArchiveTab($livewire)),
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
                TextColumn::make('current_sl')
                    ->label('Huidige SL')
                    ->money('usd')
                    ->sortable()
                    ->tooltip('Stop-loss zoals die nu bij je broker staat.')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->width('5.5rem')
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
                        $query->orderByRaw("CASE WHEN status = 'closed' THEN (exit_price - entry_price) * quantity ELSE (latest_close_price - entry_price) * quantity END {$direction}");
                    })
                    ->toggleable()
                    ->width('5.5rem')
                    ->visible(fn (HasTable $livewire): bool => self::isArchiveTab($livewire)),
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
                PositionRecordActions::markAsUpdated(),
                ActionGroup::make([
                    PositionRecordActions::fetchMarketData(),
                    PositionRecordActions::archive(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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

        return ($livewire->activeTab ?? 'open') === 'closed';
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
    ): TextInputColumn {
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
            ->extraCellAttributes(['class' => 'vestix-table-schild', 'style' => "min-width:{$width};width:{$width};padding-inline:0.5rem"])
            ->extraHeaderAttributes(['class' => 'vestix-table-schild', 'style' => "min-width:{$width};width:{$width}"])
            ->rules(['nullable', 'numeric']);
    }
}
