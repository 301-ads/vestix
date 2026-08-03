<?php

namespace App\Filament\Widgets;

use App\Enums\EarningsExitUrgency;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Tables\Columns\TickerColumn;
use App\Models\Position;
use App\Support\EarningsExitDisplay;
use App\Support\EarningsExitSchedule;
use App\Support\FilamentPolling;
use App\Support\SetupGradeDisplay;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class PositionsRequiringActionWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    /**
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    protected string $view = 'filament.widgets.actions-table-widget';

    public function getPollingInterval(): ?string
    {
        return FilamentPolling::INTERVAL;
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll(FilamentPolling::INTERVAL)
            ->columnManager(false)
            ->striped(false)
            ->heading(fn (): string|HtmlString => $this->buildHeading())
            ->searchable(false)
            ->query(fn (): Builder => $this->actionableQuery())
            ->recordUrl(fn (Position $record): string => $record->status === 'scout'
                ? ScoutResource::getUrl('edit', ['record' => $record])
                : PositionResource::getUrl('edit', ['record' => $record]))
            ->recordClasses(fn (Position $record): array => [
                'vestix-action-row',
                'vestix-action-row--'.$this->formatActionAccent($record),
            ])
            ->columns([
                TickerColumn::wrap(
                    TextColumn::make('ticker')
                        ->label('Ticker')
                        ->width('6rem'),
                    showDirectionIcon: true,
                ),
                TextColumn::make('action_type')
                    ->label('Type')
                    ->badge()
                    ->alignStart()
                    ->state(fn (Position $record): string => $this->formatActionTypeLabel($record))
                    ->color(fn (Position $record): string => $this->formatActionAccent($record))
                    ->width('7rem'),
                TextColumn::make('instruction')
                    ->label('Instructie')
                    ->wrap()
                    ->html()
                    ->state(fn (Position $record): HtmlString => $record->buy_stop_review_required_on !== null
                        ? $this->formatBuyStopInstructionHtml($record)
                        : $this->formatInstructionHtml($record)),
            ])
            ->recordActions([
                $this->outlinedRowAction(PositionRecordActions::markTarget1LimitPlaced()),
                $this->outlinedRowAction(PositionRecordActions::markInitialSlPlaced()),
                $this->outlinedRowAction(PositionRecordActions::markAsUpdated()),
                $this->outlinedRowAction(PositionRecordActions::holdThroughEarnings()),
                $this->outlinedRowAction(PositionRecordActions::archive())
                    ->visible(fn (Position $record): bool => in_array($record->primaryActionType(), [
                        Position::PRIMARY_ACTION_EARNINGS,
                        Position::PRIMARY_ACTION_LIQUIDATION,
                    ], true)),
                $this->outlinedRowAction(PositionRecordActions::rolloverBuyStop(iconButton: false)),
                $this->outlinedRowAction(PositionRecordActions::editBuyStopEntry(ScoutResource::class, iconButton: false))
                    ->color('gray'),
                $this->outlinedRowAction(PositionRecordActions::cancelBuyStopSetup(iconButton: false)),
            ])
            // Compact empty: header + gray "0" badge only — no tall icon/copy empty state.
            ->emptyState(new HtmlString('<div class="fi-ta-empty-state vestix-actions-empty--compact" aria-hidden="true"></div>'))
            ->paginated(false);
    }

    private function actionableQuery(): Builder
    {
        $userId = auth()->id() ?? 0;
        $ids = Position::requiringActionForUser($userId)
            ->pluck('id')
            ->merge(Position::requiringBuyStopReviewForUser($userId)->pluck('id'))
            ->unique()
            ->values();

        return Position::query()
            ->forUser($userId)
            ->when(
                $ids->isNotEmpty(),
                fn (Builder $query): Builder => $query->whereIn('id', $ids),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->with('asset')
            ->orderByRaw("CASE
                WHEN status = 'open' AND latest_close_price IS NOT NULL AND current_sl IS NOT NULL AND latest_close_price <= current_sl THEN 0
                WHEN buy_stop_review_required_on IS NOT NULL THEN 2
                ELSE 1
            END")
            ->orderBy('ticker');
    }

    /**
     * @return Collection<string, int>
     */
    public function getStatusColorCountsProperty(): Collection
    {
        $userId = auth()->id() ?? 0;

        $counts = Position::requiringActionForUser($userId)
            ->groupBy(fn (Position $position): string => $this->formatActionAccent($position))
            ->map(fn (Collection $group): int => $group->count());

        $buyStopCount = Position::requiringBuyStopReviewForUser($userId)->count();

        if ($buyStopCount > 0) {
            $counts['warning'] = (int) ($counts['warning'] ?? 0) + $buyStopCount;
        }

        return $counts;
    }

    public function formatActionTypeLabel(Position $record): string
    {
        if ($record->buy_stop_review_required_on !== null) {
            return 'Buy-stop';
        }

        return match ($record->primaryActionType()) {
            Position::PRIMARY_ACTION_TARGET_1 => 'Target 1',
            Position::PRIMARY_ACTION_LIQUIDATION => 'Liquidatie',
            Position::PRIMARY_ACTION_EARNINGS => 'Earnings',
            Position::PRIMARY_ACTION_UPDATE_SL => 'Stop-Loss',
            Position::PRIMARY_ACTION_PLACE_INITIAL_SL => 'Initial SL',
            default => 'Actie',
        };
    }

    public function formatInstruction(Position $record): string
    {
        return match ($record->primaryActionType()) {
            Position::PRIMARY_ACTION_TARGET_1 => $record->userUsesRevolutWorkflow()
                ? sprintf(
                    'Target 1 bereikt op $%s. Pas SL aan, verkoop %d%%, zet runner-SL op breakeven.',
                    number_format((float) ($record->target_1_price ?? 0), 2),
                    (int) round($record->effective_first_tranche_fraction * 100),
                )
                : sprintf(
                    'Stel Limit Sell in op $%s voor %d%% van je positie.',
                    number_format((float) ($record->target_1_price ?? 0), 2),
                    (int) round($record->effective_first_tranche_fraction * 100),
                ),
            Position::PRIMARY_ACTION_LIQUIDATION => sprintf(
                'Koers ($%s) raakte je stop-loss ($%s). Sluit de positie (liquidatie).',
                number_format((float) ($record->latest_close_price ?? 0), 2),
                number_format((float) ($record->current_sl ?? 0), 2),
            ),
            Position::PRIMARY_ACTION_EARNINGS => EarningsExitDisplay::dashboardInstruction($record),
            Position::PRIMARY_ACTION_UPDATE_SL => sprintf(
                'Verhoog Stop-Loss van $%s naar $%s (+$%s).',
                number_format((float) $record->current_sl, 2),
                number_format((float) ($record->new_sl ?? 0), 2),
                number_format(((float) ($record->new_sl ?? 0)) - (float) $record->current_sl, 2),
            ),
            Position::PRIMARY_ACTION_PLACE_INITIAL_SL => sprintf(
                'Stel Stop-Loss in op $%s bij je broker.',
                number_format((float) ($record->current_sl ?? 0), 2),
            ),
            default => '—',
        };
    }

    public function formatInstructionHtml(Position $record): HtmlString
    {
        $emphasis = 'font-semibold text-gray-950 dark:text-white';

        return match ($record->primaryActionType()) {
            Position::PRIMARY_ACTION_UPDATE_SL => new HtmlString(sprintf(
                'Verhoog Stop-Loss van $%s naar <span class="%s">$%s</span> (+$%s).',
                number_format((float) $record->current_sl, 2),
                $emphasis,
                number_format((float) ($record->new_sl ?? 0), 2),
                number_format(((float) ($record->new_sl ?? 0)) - (float) $record->current_sl, 2),
            )),
            Position::PRIMARY_ACTION_PLACE_INITIAL_SL => new HtmlString(sprintf(
                'Stel Stop-Loss in op <span class="%s">$%s</span> bij je broker.',
                $emphasis,
                number_format((float) ($record->current_sl ?? 0), 2),
            )),
            Position::PRIMARY_ACTION_TARGET_1 => new HtmlString($record->userUsesRevolutWorkflow()
                ? sprintf(
                    'Target 1 bereikt op <span class="%s">$%s</span>. Pas SL aan, verkoop %d%%, zet runner-SL op breakeven.',
                    $emphasis,
                    number_format((float) ($record->target_1_price ?? 0), 2),
                    (int) round($record->effective_first_tranche_fraction * 100),
                )
                : sprintf(
                    'Stel Limit Sell in op <span class="%s">$%s</span> voor %d%% van je positie.',
                    $emphasis,
                    number_format((float) ($record->target_1_price ?? 0), 2),
                    (int) round($record->effective_first_tranche_fraction * 100),
                )),
            default => new HtmlString(e($this->formatInstruction($record))),
        };
    }

    public function formatBuyStopInstruction(Position $record): string
    {
        return 'Beoordeel open buy-stop: order is gisteren niet geraakt. Is de setup vandaag nog geldig?';
    }

    public function formatBuyStopInstructionHtml(Position $record): HtmlString
    {
        $instruction = e($this->formatBuyStopInstruction($record));
        $hint = $this->formatBuyStopValidationHintHtml($record);

        if ($hint === null) {
            return new HtmlString($instruction);
        }

        return new HtmlString($instruction.' '.$hint->toHtml());
    }

    public function formatBuyStopValidationHintHtml(Position $record): ?HtmlString
    {
        $hint = $record->buyStopReviewValidationHint();

        if ($hint === null) {
            $grade = SetupGradeDisplay::label($record);

            if ($grade === null) {
                return null;
            }

            return new HtmlString(sprintf(
                '<span class="font-medium">Setup: %s</span>.',
                e($grade),
            ));
        }

        return new HtmlString('<span class="font-medium text-warning-600 dark:text-warning-400">'.e($hint).'</span>');
    }

    public function formatActionAccent(Position $record): string
    {
        if ($record->buy_stop_review_required_on !== null) {
            return 'warning';
        }

        return match ($record->primaryActionType()) {
            Position::PRIMARY_ACTION_TARGET_1 => 'success',
            Position::PRIMARY_ACTION_LIQUIDATION => 'danger',
            Position::PRIMARY_ACTION_EARNINGS => match ($record->earningsExitUrgency()) {
                EarningsExitUrgency::Prepare => EarningsExitSchedule::daysUntilAction(
                    $record->effectiveEarningsDate(),
                    null,
                    $record->asset?->effectiveEarningsHour(),
                ) === 1 ? 'danger' : 'warning',
                EarningsExitUrgency::ExitToday, EarningsExitUrgency::Overdue => 'danger',
                default => 'gray',
            },
            Position::PRIMARY_ACTION_UPDATE_SL => 'info',
            Position::PRIMARY_ACTION_PLACE_INITIAL_SL => 'warning',
            default => 'gray',
        };
    }

    /**
     * Match Kapitaalbewegingen row actions: outlined/ghost buttons with compact chrome.
     */
    private function outlinedRowAction(Action $action): Action
    {
        return $action
            ->button()
            ->outlined()
            ->size('sm');
    }

    private function buildHeading(): string|HtmlString
    {
        $statusColorCounts = $this->statusColorCounts;
        $pendingCount = $statusColorCounts->sum();

        $palette = [
            'danger' => 'bg-danger-500/10 text-danger-400 ring-danger-500/20',
            'warning' => 'bg-warning-500/10 text-warning-400 ring-warning-500/20',
            'success' => 'bg-success-500/10 text-success-400 ring-success-500/20',
            'info' => 'bg-info-500/10 text-info-400 ring-info-500/20',
            'gray' => 'bg-gray-500/10 text-gray-400 ring-gray-500/20',
        ];

        $badges = '';

        if ($pendingCount === 0) {
            $badges = '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.$palette['gray'].'">0</span>';
        } else {
            foreach ($palette as $color => $classes) {
                $count = (int) ($statusColorCounts[$color] ?? 0);

                if ($count > 0) {
                    $badges .= '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.$classes.'">'.$count.'</span>';
                }
            }
        }

        return new HtmlString(
            '<span class="inline-flex flex-wrap items-center gap-2">'
            .'<span>Acties vereist</span>'
            .$badges
            .'</span>'
        );
    }
}
