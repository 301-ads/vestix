<?php

namespace App\Filament\Widgets;

use App\Services\AlphaTrackerService;
use Filament\Widgets\Widget;

class PerformanceComingSoonWidget extends Widget
{
    protected string $view = 'filament.widgets.performance-coming-soon-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function hasEnoughSnapshots(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(AlphaTrackerService::class)->hasEnoughSnapshots($user);
    }
}
