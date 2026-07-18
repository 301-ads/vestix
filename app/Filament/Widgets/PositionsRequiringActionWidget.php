<?php

namespace App\Filament\Widgets;

use App\Enums\EarningsExitUrgency;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use App\Support\EarningsExitDisplay;
use App\Support\EarningsExitSchedule;
use App\Support\FilamentPolling;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class PositionsRequiringActionWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.positions-requiring-action-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    /**
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    public function boot(): void
    {
        // Unique per-row action names must be re-cached on every Livewire request
        // so mountAction() can resolve them (cachedActions is not persisted).
        // Query directly — do not touch the computed actionablePositions property here,
        // or Livewire memoizes a stale list before callMountedAction updates records.
        foreach ($this->loadActionablePositions() as $position) {
            $this->actionForPosition($position);
            $this->secondaryActionForPosition($position);
        }
    }

    public function getPollingInterval(): ?string
    {
        return FilamentPolling::INTERVAL;
    }

    public function getDefaultActionRecord(Action $action): ?Model
    {
        $arguments = $action->getArguments();
        $key = $arguments['record'] ?? $arguments['recordKey'] ?? null;

        return $this->resolveActionPosition($key);
    }

    /**
     * @return EloquentCollection<int, Position>
     */
    public function getActionablePositionsProperty(): EloquentCollection
    {
        return $this->loadActionablePositions();
    }

    /**
     * @return EloquentCollection<int, Position>
     */
    private function loadActionablePositions(): EloquentCollection
    {
        $userId = auth()->id() ?? 0;
        $actionableIds = Position::requiringActionForUser($userId)->pluck('id');

        return Position::query()
            ->forUser($userId)
            ->when(
                $actionableIds->isNotEmpty(),
                fn ($query) => $query->whereIn('id', $actionableIds),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->with('asset')
            ->orderByRaw('CASE WHEN latest_close_price <= current_sl THEN 0 ELSE 1 END')
            ->orderBy('ticker')
            ->get();
    }

    /**
     * @return Collection<string, int>
     */
    public function getStatusColorCountsProperty(): Collection
    {
        return $this->actionablePositions
            ->groupBy(fn (Position $position): string => $this->formatActionAccent($position))
            ->map(fn (Collection $group): int => $group->count());
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
        return match ($record->primaryActionType()) {
            Position::PRIMARY_ACTION_UPDATE_SL => new HtmlString(sprintf(
                'Verhoog Stop-Loss van $%s naar <span class="vestix-action-todo__new-sl">$%s</span> (+$%s).',
                number_format((float) $record->current_sl, 2),
                number_format((float) ($record->new_sl ?? 0), 2),
                number_format(((float) ($record->new_sl ?? 0)) - (float) $record->current_sl, 2),
            )),
            Position::PRIMARY_ACTION_PLACE_INITIAL_SL => new HtmlString(sprintf(
                'Stel Stop-Loss in op <span class="vestix-action-todo__new-sl">$%s</span> bij je broker.',
                number_format((float) ($record->current_sl ?? 0), 2),
            )),
            Position::PRIMARY_ACTION_TARGET_1 => new HtmlString($record->userUsesRevolutWorkflow()
                ? sprintf(
                    'Target 1 bereikt op <span class="vestix-action-todo__new-sl">$%s</span>. Pas SL aan, verkoop %d%%, zet runner-SL op breakeven.',
                    number_format((float) ($record->target_1_price ?? 0), 2),
                    (int) round($record->effective_first_tranche_fraction * 100),
                )
                : sprintf(
                    'Stel Limit Sell in op <span class="vestix-action-todo__new-sl">$%s</span> voor %d%% van je positie.',
                    number_format((float) ($record->target_1_price ?? 0), 2),
                    (int) round($record->effective_first_tranche_fraction * 100),
                )),
            default => new HtmlString(e($this->formatInstruction($record))),
        };
    }

    public function formatActionAccent(Position $record): string
    {
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

    public function actionForPosition(Position $position): ?Action
    {
        $factory = match ($position->primaryActionType()) {
            Position::PRIMARY_ACTION_TARGET_1 => 'makeMarkLimitPlaced',
            Position::PRIMARY_ACTION_PLACE_INITIAL_SL => 'makeMarkInitialSlPlaced',
            Position::PRIMARY_ACTION_UPDATE_SL => 'makeMarkAsUpdated',
            Position::PRIMARY_ACTION_EARNINGS => 'makeArchive',
            default => null,
        };

        if ($factory === null) {
            return null;
        }

        return $this->cacheAction($this->uniqueRecordAction($this->{$factory}(), $position));
    }

    public function secondaryActionForPosition(Position $position): ?Action
    {
        if ($position->primaryActionType() !== Position::PRIMARY_ACTION_EARNINGS) {
            return null;
        }

        return $this->cacheAction($this->uniqueRecordAction($this->makeHoldThroughEarnings(), $position));
    }

    private function uniqueRecordAction(Action $action, Position $position): Action
    {
        $name = $action->getName().'_'.$position->getKey();

        $action->name($name);

        // Row is already filtered by primaryActionType(); keep the button mountable
        // even before Filament has injected the record into visible() callbacks.
        $action->visible(true);

        // Scope loading-disabled to this button only — without wire:target, wire:poll
        // on the list disables every Update button on the component.
        $action->extraAttributes([
            'wire:target' => "mountAction('{$name}')",
        ], merge: true);

        return $action(['record' => $position->getKey()])->record($position);
    }

    private function makeMarkLimitPlaced(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::markTarget1LimitPlaced());
    }

    private function makeMarkInitialSlPlaced(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::markInitialSlPlaced());
    }

    private function makeMarkAsUpdated(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::markAsUpdated());
    }

    private function makeHoldThroughEarnings(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::holdThroughEarnings());
    }

    private function makeArchive(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::archive());
    }

    private function configureRecordAction(Action $action): Action
    {
        return $action
            ->resolveRecordUsing(function (array $arguments) use ($action): ?Position {
                $key = $arguments['key']
                    ?? $action->getArguments()['record']
                    ?? $action->getArguments()['recordKey']
                    ?? null;

                return $this->resolveActionPosition($key);
            })
            ->before(function (Action $action): void {
                $record = $this->getDefaultActionRecord($action);

                if ($record instanceof Position) {
                    $action->record($record);
                }
            });
    }

    private function resolveActionPosition(mixed $key): ?Position
    {
        if ($key === null || $key === '') {
            return null;
        }

        if ($key instanceof Position) {
            return $key;
        }

        return Position::query()
            ->forUser(auth()->id() ?? 0)
            ->find($key);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'heading' => $this->buildHeading(
                $this->actionablePositions->count(),
                $this->statusColorCounts,
            ),
        ];
    }

    /**
     * @param  Collection<string, int>  $statusColorCounts
     */
    private function buildHeading(int $pendingCount, Collection $statusColorCounts): string|HtmlString
    {
        if ($pendingCount === 0) {
            return 'Acties vereist';
        }

        $palette = [
            'danger' => 'bg-danger-500/10 text-danger-400 ring-danger-500/20',
            'warning' => 'bg-warning-500/10 text-warning-400 ring-warning-500/20',
            'success' => 'bg-success-500/10 text-success-400 ring-success-500/20',
            'info' => 'bg-info-500/10 text-info-400 ring-info-500/20',
            'gray' => 'bg-gray-500/10 text-gray-400 ring-gray-500/20',
        ];

        $badges = '';

        foreach ($palette as $color => $classes) {
            $count = (int) ($statusColorCounts[$color] ?? 0);

            if ($count > 0) {
                $badges .= '<span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.$classes.'">'.$count.'</span>';
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
