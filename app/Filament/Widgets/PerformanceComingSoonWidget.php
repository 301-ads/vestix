<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class PerformanceComingSoonWidget extends Widget
{
    protected string $view = 'filament.widgets.performance-coming-soon-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';
}
