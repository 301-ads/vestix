<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class PositionsRequiringActionWidget extends TableWidget
{
    protected string $view = 'filament.widgets.positions-requiring-action-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $userId = auth()->id() ?? 0;

        $scoped = Position::query()->forUser($userId);
        $updateCount = (clone $scoped)->requiresSlUpdate()->count();
        $liquidationCount = (clone $scoped)->stoppedOut()->count();
        $pendingCount = $updateCount + $liquidationCount;

        return $table
            ->columnManager(false)
            ->striped(false)
            ->heading($this->buildHeading($pendingCount, $updateCount, $liquidationCount))
            ->searchable(false)
            ->query(fn (): Builder => Position::query()
                ->forUser($userId)
                ->requiresAction()
                ->with('asset')
                ->orderByRaw('CASE WHEN latest_close_price <= current_sl THEN 0 ELSE 1 END')
                ->orderBy('ticker'))
            ->columns([
                TextColumn::make('action_command')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'UPDATE' => 'Update',
                        'STOPPED OUT' => 'Liquidatie',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'UPDATE' => 'warning',
                        'STOPPED OUT' => 'danger',
                        default => 'gray',
                    }),
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker'),
                ),
                TextColumn::make('current_sl')
                    ->label('Huidige SL')
                    ->money('usd'),
                TextColumn::make('new_sl')
                    ->label('Nieuwe SL')
                    ->state(fn (Position $record): ?float => $record->action_command === 'UPDATE'
                        ? $record->new_sl
                        : null)
                    ->money('usd')
                    ->placeholder('—')
                    ->copyable(fn (Position $record): bool => $record->action_command === 'UPDATE' && $record->new_sl !== null)
                    ->copyMessage('SL-prijs gekopieerd')
                    ->copyableState(fn (Position $record): ?string => $record->new_sl !== null
                        ? number_format((float) $record->new_sl, 2, '.', '')
                        : null),
                TextColumn::make('sl_difference')
                    ->label('Verschil')
                    ->state(fn (Position $record): ?float => $record->action_command === 'UPDATE'
                        ? ($record->new_sl ?? 0) - (float) $record->current_sl
                        : null)
                    ->money('usd')
                    ->color('success')
                    ->placeholder('—'),
            ])
            ->recordActions([
                PositionRecordActions::markAsUpdated(),
            ])
            ->emptyStateHeading('Geen acties vereist')
            ->emptyStateDescription('Alle stop-losses zijn up-to-date en geen posities onder hun stop-loss.')
            ->paginated(false);
    }

    private function buildHeading(int $pendingCount, int $updateCount, int $liquidationCount): string|HtmlString
    {
        if ($pendingCount === 0) {
            return 'Acties vereist';
        }

        $badges = '';

        if ($liquidationCount > 0) {
            $badges .= '<span class="inline-flex items-center rounded-md bg-danger-500/10 px-2 py-0.5 text-xs font-medium text-danger-400 ring-1 ring-inset ring-danger-500/20">'.$liquidationCount.'</span>';
        }

        if ($updateCount > 0) {
            $badges .= '<span class="inline-flex items-center rounded-md bg-warning-500/10 px-2 py-0.5 text-xs font-medium text-warning-400 ring-1 ring-inset ring-warning-500/20">'.$updateCount.'</span>';
        }

        return new HtmlString(
            '<span class="inline-flex flex-wrap items-center gap-2">'
            .'<span>Acties vereist</span>'
            .$badges
            .'</span>'
        );
    }
}
