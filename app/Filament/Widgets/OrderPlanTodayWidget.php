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

    protected static ?int $sort = 3;

    /**
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 4,
    ];

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
