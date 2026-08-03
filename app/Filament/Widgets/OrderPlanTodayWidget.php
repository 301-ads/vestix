<?php

namespace App\Filament\Widgets;

use App\Support\FilamentPolling;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class OrderPlanTodayWidget extends Widget
{
    protected string $view = 'filament.widgets.order-plan-today-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    #[On('order-plan-updated')]
    public function refreshPlan(): void
    {
        //
    }

    public static function canView(): bool
    {
        return auth()->check();
    }

    public function getPollingInterval(): ?string
    {
        return FilamentPolling::INTERVAL;
    }
}
