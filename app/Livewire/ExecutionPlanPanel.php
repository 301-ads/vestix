<?php

namespace App\Livewire;

use App\Models\Position;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class ExecutionPlanPanel extends Component
{
    public int $refreshToken = 0;

    #[On('order-plan-updated')]
    public function refreshPlan(): void
    {
        $this->refreshToken++;
    }

    public function planCount(): int
    {
        $userId = auth()->id();

        if ($userId === null) {
            return 0;
        }

        // Winkelwagen + “Actief” (buy-stops al gemarkeerd) — anders lijkt de cart leeg
        // na een foutieve bulk-add die scouts op broker-Pending zette.
        return Position::orderPlanForUser((int) $userId)->count()
            + Position::activeOrderPlanForUser((int) $userId)->count();
    }

    public function render(): View
    {
        $planCount = $this->planCount();

        return view('livewire.execution-plan-panel', [
            'planCount' => $planCount,
            'contentKey' => 'execution-plan-panel-content-'.$planCount.'-'.$this->refreshToken,
        ]);
    }
}
