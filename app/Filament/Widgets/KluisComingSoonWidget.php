<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\VestixKluis;
use Filament\Widgets\Widget;

class KluisComingSoonWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.kluis-performance-cta';

    protected int|string|array $columnSpan = 'full';

    public function kluisUrl(): string
    {
        return VestixKluis::getUrl();
    }
}
