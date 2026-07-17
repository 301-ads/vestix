<?php

namespace App\Filament\Widgets;

use App\Services\BankrollSnapshotService;
use App\Services\Ibkr\IbkrSyncHealth;
use App\Support\FilamentNotifier;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class BankrollUpdateWidget extends Widget
{
    protected string $view = 'filament.widgets.bankroll-update-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public ?string $bankrollAmount = null;

    public bool $ibkrStale = false;

    public function mount(): void
    {
        $user = Auth::user();
        $bankroll = $user?->trading_bankroll;

        $this->bankrollAmount = $bankroll !== null
            ? number_format((float) $bankroll, 2, '.', '')
            : null;

        $this->ibkrStale = $user !== null && app(IbkrSyncHealth::class)->isStale($user)
            && ($user->ibkr_last_success_at !== null || (string) config('vestix.ibkr.reader', 'stub') === 'flex');
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        $health = app(IbkrSyncHealth::class);
        $usesIbkrSync = $user->ibkr_last_success_at !== null
            || (string) config('vestix.ibkr.reader', 'stub') === 'flex'
            || (string) config('vestix.bankroll_tracker.source', 'manual') === 'ibkr';

        if ($usesIbkrSync) {
            return $health->isStale($user);
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
