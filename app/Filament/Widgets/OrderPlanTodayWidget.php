<?php

namespace App\Filament\Widgets;

use App\Models\Position;
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
        $userId = auth()->id();

        if ($userId === null) {
            return false;
        }

        return Position::orderPlanForUser($userId)->isNotEmpty();
    }

    public function getPollingInterval(): ?string
    {
        return FilamentPolling::INTERVAL;
    }
}
