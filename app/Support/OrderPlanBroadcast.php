<?php

namespace App\Support;

use App\Livewire\ExecutionPlanContent;
use App\Livewire\ExecutionPlanPanel;
use App\Filament\Widgets\OrderPlanTodayWidget;
use Livewire\Component;

/**
 * Notifies every Order Plan surface (topbar cart, dashboard widget, nested content).
 */
final class OrderPlanBroadcast
{
    public static function dispatch(Component $from): void
    {
        // Bubble to window listeners (#[On] on other components).
        $from->dispatch('order-plan-updated');

        // Explicit targets — nested / teleported cart UI can miss a bubbled event.
        $from->dispatch('order-plan-updated')->to(ExecutionPlanPanel::class);
        $from->dispatch('order-plan-updated')->to(ExecutionPlanContent::class);
        $from->dispatch('order-plan-updated')->to(OrderPlanTodayWidget::class);
    }
}
