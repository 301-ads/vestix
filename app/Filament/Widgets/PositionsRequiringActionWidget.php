<?php

namespace App\Filament\Widgets;

use App\Enums\EarningsExitUrgency;
use App\Enums\EarningsReleaseHour;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
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

    protected int|string|array $columnSpan = 'full';

    public function boot(): void
    {
        $this->cacheAction($this->markLimitPlacedAction());
        $this->cacheAction($this->markAsUpdatedAction());
        $this->cacheAction($this->archiveAction());
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
            Position::PRIMARY_ACTION_TARGET_1 => sprintf(
                'Stel Limit Sell in op $%s voor %d%% van je positie.',
                number_format((float) ($record->target_1_price ?? 0), 2),
                (int) round($record->effective_first_tranche_fraction * 100),
            ),
            Position::PRIMARY_ACTION_LIQUIDATION => sprintf(
                'Koers ($%s) raakte je stop-loss ($%s). Sluit de positie (liquidatie).',
                number_format((float) ($record->latest_close_price ?? 0), 2),
                number_format((float) ($record->current_sl ?? 0), 2),
            ),
            Position::PRIMARY_ACTION_EARNINGS => $this->formatEarningsInstruction($record),
            Position::PRIMARY_ACTION_UPDATE_SL => sprintf(
                'Verhoog Stop-Loss van $%s naar $%s (+$%s).',
                number_format((float) $record->current_sl, 2),
                number_format((float) ($record->new_sl ?? 0), 2),
                number_format(((float) ($record->new_sl ?? 0)) - (float) $record->current_sl, 2),
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
            Position::PRIMARY_ACTION_TARGET_1 => new HtmlString(sprintf(
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
                EarningsExitUrgency::Prepare => 'warning',
                EarningsExitUrgency::ExitToday, EarningsExitUrgency::Overdue => 'danger',
                default => 'gray',
            },
            Position::PRIMARY_ACTION_UPDATE_SL => 'info',
            default => 'gray',
        };
    }

    public function actionForPosition(Position $position): ?Action
    {
        $method = match ($position->primaryActionType()) {
            Position::PRIMARY_ACTION_TARGET_1 => 'markLimitPlacedAction',
            Position::PRIMARY_ACTION_UPDATE_SL => 'markAsUpdatedAction',
            Position::PRIMARY_ACTION_EARNINGS => 'archiveAction',
            default => null,
        };

        if ($method === null) {
            return null;
        }

        $action = $this->cacheAction($this->{$method}());

        return $action(['record' => $position->getKey()])->record($position);
    }

    public function markLimitPlacedAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::markTarget1LimitPlaced());
    }

    public function markAsUpdatedAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::markAsUpdated());
    }

    public function archiveAction(): Action
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

    private function formatEarningsInstruction(Position $record): string
    {
        $earningsDate = $record->effectiveEarningsDate();
        $dateLabel = $earningsDate?->locale('nl')->isoFormat('D MMMM') ?? 'binnenkort';

        $hour = $record->asset?->effectiveEarningsHour() ?? EarningsReleaseHour::Unknown;
        $timingSuffix = match ($hour) {
            EarningsReleaseHour::Bmo => ' (voorbeurs)',
            EarningsReleaseHour::Amc => ' (nabeurs)',
            default => '',
        };

        return match ($record->earningsExitUrgency()) {
            EarningsExitUrgency::Prepare => sprintf(
                'Earnings op %s%s. Laat de positie nog lopen — nog niets doen.',
                $dateLabel,
                $timingSuffix,
            ),
            EarningsExitUrgency::ExitToday => sprintf(
                'Earnings %s%s. Sluit de positie vandaag handmatig vóór de slotbel (22:00) en archiveer.',
                $dateLabel,
                $timingSuffix,
            ),
            EarningsExitUrgency::Overdue => sprintf(
                'Earnings-exit (%s) is te laat. Sluit de positie direct en archiveer.',
                $dateLabel,
            ),
            default => 'Sluit de positie vóór de earnings.',
        };
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
