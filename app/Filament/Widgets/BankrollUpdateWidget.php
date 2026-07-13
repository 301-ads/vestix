<?php

namespace App\Filament\Widgets;

use App\Services\BankrollSnapshotService;
use App\Support\FilamentNotifier;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class BankrollUpdateWidget extends Widget
{
    protected string $view = 'filament.widgets.bankroll-update-widget';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public ?string $bankrollAmount = null;

    public function mount(): void
    {
        $bankroll = Auth::user()?->trading_bankroll;

        $this->bankrollAmount = $bankroll !== null
            ? number_format((float) $bankroll, 2, '.', '')
            : null;
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(BankrollSnapshotService::class)->isUpdateDue($user);
    }

    public function saveBankroll(): void
    {
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $validated = validator(
            ['bankrollAmount' => $this->bankrollAmount],
            ['bankrollAmount' => ['required', 'numeric', 'min:0.01']],
            [
                'bankrollAmount.required' => 'Vul je bankroll in.',
                'bankrollAmount.min' => 'Bankroll moet groter zijn dan nul.',
            ],
        )->validate();

        app(BankrollSnapshotService::class)->recordSnapshot(
            $user,
            (float) $validated['bankrollAmount'],
        );

        FilamentNotifier::send(
            title: 'Bankroll bijgewerkt',
            body: 'Je wekelijkse snapshot is opgeslagen. Alpha Tracker is bijgewerkt.',
        );

        $this->dispatch('$refresh');
    }
}
