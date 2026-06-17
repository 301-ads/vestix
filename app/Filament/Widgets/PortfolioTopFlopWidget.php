<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PortfolioTopFlopWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->columnManager(false)
            ->striped(false)
            ->heading('Portfolio Top / Flop')
            ->searchable(false)
            ->recordUrl(fn (Position $record): string => PositionResource::getUrl('edit', ['record' => $record]))
            ->query(fn (): Builder => Position::open()
                ->forUser(auth()->id())
                ->with('asset')
                ->whereNotNull('latest_close_price')
                ->orderByRaw('((latest_close_price - entry_price) / NULLIF(entry_price, 0)) * 100 DESC'))
            ->columns([
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker'),
                ),
                TextColumn::make('unrealized_pnl_percentage')
                    ->label('P&L (%)')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->color(fn ($state) => ($state ?? 0) >= 0 ? 'success' : 'danger'),
                TextColumn::make('unrealized_pnl')
                    ->label('P&L ($)')
                    ->formatStateUsing(fn ($state): string => ($state >= 0 ? '+' : '-').'$'.number_format(abs((float) $state), 2))
                    ->color(fn ($state) => ($state ?? 0) >= 0 ? 'success' : 'danger'),
                TextColumn::make('locked_in_profit_dollars')
                    ->label('Locked')
                    ->badge()
                    ->formatStateUsing(fn (float $state): string => $state > 0
                        ? '+$'.number_format($state, 2)
                        : 'Geen lock')
                    ->color(fn (float $state): string => $state > 0 ? 'info' : 'gray')
                    ->icon(fn (float $state): ?string => $state > 0 ? 'heroicon-m-lock-closed' : null)
                    ->tooltip(fn (Position $record): string => $record->locked_in_profit_dollars > 0
                        ? 'Winst veiliggesteld: je stop-loss staat boven entry. Laat je winnaars lopen.'
                        : 'Stop-loss staat nog op of onder entry — winst is nog niet gelockt.'),
            ])
            ->emptyStateHeading('Geen open posities met marktdata')
            ->emptyStateDescription('Voeg posities toe of haal marktdata op via API sync.')
            ->paginated(false);
    }
}
