<?php

namespace App\Filament\Widgets;

use App\Services\AlphaTrackerService;
use Filament\Widgets\Widget;

class PerformanceComingSoonWidget extends Widget
{
    protected string $view = 'filament.widgets.performance-coming-soon-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * Only the empty-state CTA — edge analytics replaces the old "coming soon" branch.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ! app(AlphaTrackerService::class)->hasEnoughSnapshots($user);
    }

    public function hasEnoughSnapshots(): bool
    {
        return false;
    }
}
