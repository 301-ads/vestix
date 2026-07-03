<?php

namespace App\Filament\Widgets;

use App\Enums\EarningsExitUrgency;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use App\Support\AlertMessageBuilder;
use App\Support\FilamentPolling;
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
        $actionablePositions = Position::requiringActionForUser($userId);
        $updateCount = $actionablePositions
            ->filter(fn (Position $position): bool => $position->action_command === 'UPDATE')
            ->count();
        $liquidationCount = $actionablePositions
            ->filter(fn (Position $position): bool => $position->action_command === 'STOPPED OUT')
            ->count();
        $earningsCount = $actionablePositions
            ->filter(fn (Position $position): bool => $position->requiresEarningsExit())
            ->count();
        $pendingCount = $actionablePositions->count();

        return $table
            ->poll(FilamentPolling::INTERVAL)
            ->columnManager(false)
            ->striped(false)
            ->heading($this->buildHeading($pendingCount, $updateCount, $liquidationCount, $earningsCount))
            ->searchable(false)
            ->query(function () use ($userId): Builder {
                $actionableIds = Position::requiringActionForUser($userId)->pluck('id');

                return Position::query()
                    ->forUser($userId)
                    ->when(
                        $actionableIds->isNotEmpty(),
                        fn (Builder $query): Builder => $query->whereIn('id', $actionableIds),
                        fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                    )
                    ->with('asset')
                    ->orderByRaw('CASE WHEN latest_close_price <= current_sl THEN 0 ELSE 1 END')
                    ->orderBy('ticker');
            })
            ->columns([
                TextColumn::make('action_command')
                    ->label('Status')
                    ->badge()
                    ->alignStart()
                    ->formatStateUsing(fn (Position $record): string => $this->formatStatusLabel($record))
                    ->color(fn (Position $record): string => $this->formatStatusColor($record))
                    ->extraCellAttributes(['class' => 'vestix-status-badge-cell'])
                    ->extraHeaderAttributes(['class' => 'vestix-status-badge-cell']),
                // ->width('6.5rem'),
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker'),
                ),
                TextColumn::make('current_sl')
                    ->label('Huidige SL')
                    ->money('usd')
                    ->placeholder('—'),
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
                PositionRecordActions::markAsUpdated()
                    ->visible(fn (Position $record): bool => $record->action_command === 'UPDATE'),
                PositionRecordActions::archive()
                    ->visible(fn (Position $record): bool => $record->requiresEarningsExit()),
            ])
            ->emptyStateHeading('Geen acties vereist')
            ->emptyStateDescription('Alle stop-losses zijn up-to-date en geen posities onder hun stop-loss.')
            ->paginated(false);
    }

    private function formatStatusLabel(Position $record): string
    {
        if ($record->requiresEarningsExit()) {
            return match ($record->earningsExitUrgency()) {
                EarningsExitUrgency::Prepare => 'Earnings — bereid exit voor',
                EarningsExitUrgency::ExitToday => 'Earnings — sluit vandaag',
                EarningsExitUrgency::Overdue => 'Earnings — te laat!',
                default => AlertMessageBuilder::formatActionLabel($record),
            };
        }

        return match ($record->action_command) {
            'UPDATE' => 'Update',
            'STOPPED OUT' => 'Liquidatie',
            default => $record->action_command,
        };
    }

    private function formatStatusColor(Position $record): string
    {
        if ($record->requiresEarningsExit()) {
            return match ($record->earningsExitUrgency()) {
                EarningsExitUrgency::Prepare => 'warning',
                EarningsExitUrgency::ExitToday, EarningsExitUrgency::Overdue => 'danger',
                default => 'gray',
            };
        }

        return match ($record->action_command) {
            'UPDATE' => 'warning',
            'STOPPED OUT' => 'danger',
            default => 'gray',
        };
    }

    private function buildHeading(int $pendingCount, int $updateCount, int $liquidationCount, int $earningsCount): string|HtmlString
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

        if ($earningsCount > 0) {
            $badges .= '<span class="inline-flex items-center rounded-md bg-orange-500/10 px-2 py-0.5 text-xs font-medium text-orange-400 ring-1 ring-inset ring-orange-500/20">'.$earningsCount.'</span>';
        }

        return new HtmlString(
            '<span class="inline-flex flex-wrap items-center gap-2">'
            .'<span>Acties vereist</span>'
            .$badges
            .'</span>'
        );
    }
}
