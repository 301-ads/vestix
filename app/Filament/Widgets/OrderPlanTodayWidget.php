<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use App\Models\User;
use App\Services\SmartAllocationService;
use App\Support\FilamentPolling;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class OrderPlanTodayWidget extends Widget
{
    protected string $view = 'filament.widgets.order-plan-today-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

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

    public function planCount(): int
    {
        $userId = auth()->id();

        if ($userId === null) {
            return 0;
        }

        return Position::orderPlanForUser($userId)->count();
    }

    public function totalInvestment(): float
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return 0.0;
        }

        $scouts = Position::orderPlanForUser((int) $user->id);

        if ($scouts->isEmpty()) {
            return 0.0;
        }

        $mode = (string) config(
            'vestix.smart_sizing.default_mode',
            SmartAllocationService::MODE_SMART,
        );
        $result = app(SmartAllocationService::class)->allocate($user, $scouts, $mode);

        return (float) collect($result['allocations'])->sum('investment');
    }
}
