<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PositionsRequiringLiquidationWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $pendingCount = Position::query()->stoppedOut()->count();

        return $table
            ->heading($this->buildHeading($pendingCount))
            ->searchable(false)
            ->query(fn (): Builder => Position::query()->stoppedOut())
            ->columns([
                TextColumn::make('ticker')
                    ->label('Ticker'),
                TextColumn::make('current_sl')
                    ->label('Huidige SL')
                    ->money('usd'),
                TextColumn::make('latest_close_price')
                    ->label('Close')
                    ->money('usd'),
                TextColumn::make('action_command')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'STOPPED OUT' => 'LIQUIDATIE',
                        default => $state,
                    })
                    ->color('danger'),
            ])
            ->recordActions([
                PositionRecordActions::archive(),
            ])
            ->emptyStateHeading('Geen liquidaties openstaand')
            ->emptyStateDescription('Alle posities zijn boven hun stop-loss.')
            ->paginated(false);
    }

    private function buildHeading(int $pendingCount): string | HtmlString
    {
        if ($pendingCount === 0) {
            return 'Liquidatie vereist';
        }

        return new HtmlString(
            'Liquidatie vereist <span class="ml-1 inline-flex items-center rounded-md bg-danger-500/10 px-2 py-0.5 text-xs font-medium text-danger-400 ring-1 ring-inset ring-danger-500/20">'.$pendingCount.'</span>'
        );
    }
}
