<?php

namespace App\Livewire;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use App\Models\User;
use App\Services\SmartAllocationService;
use App\Support\FilamentNotifier;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class ExecutionPlanContent extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var 'embedded'|'panel' */
    public string $layout = 'embedded';

    public string $mode = SmartAllocationService::MODE_SMART;

    public function mount(string $layout = 'embedded'): void
    {
        $this->layout = $layout === 'panel' ? 'panel' : 'embedded';
        $this->mode = (string) config(
            'vestix.smart_sizing.default_mode',
            SmartAllocationService::MODE_SMART,
        );
    }

    public function boot(): void
    {
        $this->cacheAction($this->placeOrderAction());
        $this->cacheAction($this->clearBuyStopAction());
        $this->cacheAction($this->activateScoutAction());
    }

    #[On('order-plan-updated')]
    public function refreshPlan(): void
    {
        //
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode === SmartAllocationService::MODE_EQUAL
            ? SmartAllocationService::MODE_EQUAL
            : SmartAllocationService::MODE_SMART;
    }

    public function removeFromPlan(int $positionId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $position = Position::query()
            ->forUser((int) $user->id)
            ->scout()
            ->nonLegacy()
            ->whereKey($positionId)
            ->first();

        if ($position === null || $position->market_open_reminder_on === null) {
            return;
        }

        if (! auth()->user()?->can('update', $position)) {
            return;
        }

        $ticker = (string) $position->ticker;
        $position->clearMarketOpenReminder();

        FilamentNotifier::send(
            title: 'Uit Order Plan',
            body: "{$ticker} staat niet meer in je Order Plan.",
        );

        $this->dispatch('order-plan-updated');
    }

    public function applyAllocation(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $scouts = $this->orderPlanScouts();

        if ($scouts->isEmpty()) {
            FilamentNotifier::send(
                title: 'Order Plan is leeg',
                body: 'Zet eerst scouts in je Order Plan via het winkelwagen-icoon op Mijn Radar.',
                status: 'warning',
            );

            return;
        }

        $service = app(SmartAllocationService::class);
        $result = $service->allocate($user, $scouts, $this->mode);

        if ($result['allocations'] === []) {
            FilamentNotifier::send(
                title: 'Geen allocaties',
                body: 'Geen scout voldeed aan de criteria (score, entry/SL, IBKR bankroll).',
                status: 'warning',
            );

            return;
        }

        $updated = $service->applyToPositions($scouts, $result['allocations']);

        FilamentNotifier::send(
            title: 'Budget verdeeld',
            body: sprintf(
                '%d scout(s) bijgewerkt. Plaats daarna per scout je order via Order Ticket.',
                $updated,
            ),
        );

        $this->dispatch('order-plan-updated');
    }

    public function getDefaultActionRecord(Action $action): ?Model
    {
        $arguments = $action->getArguments();
        $key = $arguments['record'] ?? $arguments['recordKey'] ?? null;

        return $this->resolveOrderPlanPosition($key);
    }

    public function placeOrderActionForPosition(Position $position): Action
    {
        return $this->cacheAction($this->placeOrderAction())(['record' => $position->getKey()])->record($position);
    }

    public function placeOrderAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::markBuyStopPlaced());
    }

    public function clearBuyStopActionForPosition(Position $position): Action
    {
        return $this->cacheAction($this->clearBuyStopAction())(['record' => $position->getKey()])->record($position);
    }

    public function clearBuyStopAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::clearBuyStop());
    }

    public function activateScoutActionForPosition(Position $position): Action
    {
        return $this->cacheAction($this->activateScoutAction())(['record' => $position->getKey()])->record($position);
    }

    public function activateScoutAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::activateScout());
    }

    /**
     * @return Collection<int, Position>
     */
    public function orderPlanScouts(): Collection
    {
        $userId = auth()->id();

        if ($userId === null) {
            return new Collection;
        }

        return Position::orderPlanForUser((int) $userId);
    }

    /**
     * @return Collection<int, Position>
     */
    public function activeOrderPlanScouts(): Collection
    {
        $userId = auth()->id();

        if ($userId === null) {
            return new Collection;
        }

        return Position::activeOrderPlanForUser((int) $userId);
    }

    public function planCount(): int
    {
        return $this->orderPlanScouts()->count();
    }

    public function activeCount(): int
    {
        return $this->activeOrderPlanScouts()->count();
    }

    /**
     * @return array{
     *     mode: string,
     *     pie: float,
     *     pie_percent: float,
     *     bankroll: float,
     *     weights_uniform: bool,
     *     allocations: list<array<string, mixed>>,
     *     exclusions: list<array{position_id: int, ticker: string, reason: string}>,
     * }
     */
    public function allocationResult(): array
    {
        $user = auth()->user();
        $empty = [
            'mode' => $this->mode,
            'pie' => 0.0,
            'pie_percent' => 0.0,
            'bankroll' => 0.0,
            'weights_uniform' => true,
            'allocations' => [],
            'exclusions' => [],
        ];

        if (! $user instanceof User) {
            return $empty;
        }

        $scouts = $this->orderPlanScouts();

        if ($scouts->isEmpty()) {
            return $empty;
        }

        return app(SmartAllocationService::class)->allocate($user, $scouts, $this->mode);
    }

    public function totalInvestment(): float
    {
        return (float) collect($this->allocationResult()['allocations'])
            ->sum('investment');
    }

    private function configureRecordAction(Action $action): Action
    {
        return $action
            ->resolveRecordUsing(function (array $arguments) use ($action): ?Position {
                $key = $arguments['key']
                    ?? $action->getArguments()['record']
                    ?? $action->getArguments()['recordKey']
                    ?? null;

                return $this->resolveOrderPlanPosition($key);
            })
            ->before(function (Action $action): void {
                $record = $this->getDefaultActionRecord($action);

                if ($record instanceof Position) {
                    $action->record($record);
                }
            })
            ->after(function (): void {
                $this->dispatch('order-plan-updated');
            });
    }

    private function resolveOrderPlanPosition(mixed $key): ?Position
    {
        if ($key === null || $key === '') {
            return null;
        }

        if ($key instanceof Position) {
            return $key;
        }

        return Position::query()
            ->forUser(auth()->id() ?? 0)
            ->scout()
            ->nonLegacy()
            ->find($key);
    }

    public function render(): View
    {
        return view('livewire.execution-plan-content', [
            'planCount' => $this->planCount(),
            'activeCount' => $this->activeCount(),
            'result' => $this->allocationResult(),
            'totalInvestment' => $this->totalInvestment(),
            'scouts' => $this->orderPlanScouts(),
            'activeScouts' => $this->activeOrderPlanScouts(),
        ]);
    }
}
