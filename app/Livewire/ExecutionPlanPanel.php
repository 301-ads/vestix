<?php

namespace App\Livewire;

use App\Models\Position;
use App\Models\User;
use App\Services\SmartAllocationService;
use App\Support\FilamentNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class ExecutionPlanPanel extends Component
{
    public string $mode = SmartAllocationService::MODE_SMART;

    public function mount(): void
    {
        $this->mode = (string) config(
            'vestix.smart_sizing.default_mode',
            SmartAllocationService::MODE_SMART,
        );
    }

    #[On('order-plan-updated')]
    public function refreshPlan(): void
    {
        // Re-render badge + allocation when Radar toggles Order Plan.
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
                body: 'Zet eerst scouts op je Order Plan via de bel op Mijn Radar.',
                status: 'warning',
            );

            return;
        }

        $service = app(SmartAllocationService::class);
        $result = $service->allocate($user, $scouts, $this->mode);

        if ($result['allocations'] === []) {
            FilamentNotifier::send(
                title: 'Geen allocaties',
                body: 'Geen scout voldeed aan de criteria (score, entry/SL, bankroll).',
                status: 'warning',
            );

            return;
        }

        $updated = $service->applyToPositions($scouts, $result['allocations']);

        FilamentNotifier::send(
            title: 'Budget verdeeld',
            body: sprintf(
                '%d scout(s) bijgewerkt. Plaats daarna per scout je order via Order plaatsen.',
                $updated,
            ),
        );

        $this->dispatch('order-plan-updated');
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

    public function planCount(): int
    {
        return $this->orderPlanScouts()->count();
    }

    /**
     * @return array{
     *     mode: string,
     *     pie: float,
     *     pie_percent: float,
     *     bankroll: float,
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

    public function render(): View
    {
        return view('livewire.execution-plan-panel', [
            'planCount' => $this->planCount(),
            'result' => $this->allocationResult(),
            'totalInvestment' => $this->totalInvestment(),
            'scouts' => $this->orderPlanScouts(),
        ]);
    }
}
