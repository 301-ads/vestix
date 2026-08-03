<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class SniperRejectSamplesWidget extends Widget
{
    protected string $view = 'filament.widgets.sniper-reject-samples-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 9;

    /**
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $samples = data_get($user->ui_preferences, 'sniper_last_rejects.samples', []);

        return is_array($samples) && $samples !== [];
    }

    /**
     * @return array{date: string|null, samples: list<array{ticker: string, reasons: list<string>}>}
     */
    public function getPayloadProperty(): array
    {
        $prefs = auth()->user()?->ui_preferences ?? [];

        return [
            'date' => data_get($prefs, 'sniper_last_rejects.date'),
            'samples' => data_get($prefs, 'sniper_last_rejects.samples', []) ?: [],
        ];
    }
}
