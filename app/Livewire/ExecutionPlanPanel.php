<?php

namespace App\Livewire;

use App\Models\Position;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class ExecutionPlanPanel extends Component
{
    #[On('order-plan-updated')]
    public function refreshPlan(): void
    {
        // Refresh badge when Order Plan membership changes.
    }

    public function planCount(): int
    {
        $userId = auth()->id();

        if ($userId === null) {
            return 0;
        }

        return Position::orderPlanForUser((int) $userId)->count();
    }

    public function render(): View
    {
        return view('livewire.execution-plan-panel', [
            'planCount' => $this->planCount(),
        ]);
    }
}
