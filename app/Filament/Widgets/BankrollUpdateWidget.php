<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Services\BankrollSnapshotService;
use App\Services\Ibkr\IbkrSyncHealth;
use App\Services\SmartAllocationService;
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

        // Escape hatch only after a real sync went stale — not when Flex is
        // configured locally but has never succeeded (common local false positive).
        $this->ibkrStale = $user !== null
            && $user->ibkr_last_success_at !== null
            && app(IbkrSyncHealth::class)->isStale($user);

        $prefill = null;

        if ($user !== null) {
            // Sizing uses min(AF, settled). Prefill that — never Alpha NLV / Revolut equity.
            $prefill = $this->ibkrStale
                ? ($user->ibkr_available_funds ?? $user->ibkr_settled_cash ?? $user->trading_bankroll)
                : $user->trading_bankroll;
        }

        $this->bankrollAmount = $prefill !== null
            ? number_format((float) $prefill, 2, '.', '')
            : null;
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        $health = app(IbkrSyncHealth::class);

        // Don't surface the dashboard card for "Flex configured, never synced".
        // That state is common locally; production with a healthy sync stays quiet.
        if ($user->ibkr_last_success_at !== null && $health->isStale($user)) {
            return true;
        }

        if ((string) config('vestix.bankroll_tracker.source', 'manual') === 'ibkr' && $health->isStale($user)) {
            return true;
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

        $amount = round((float) $validated['bankrollAmount'], 2);
        $wasStaleEscape = $this->ibkrStale;
        $snapshots = app(BankrollSnapshotService::class);

        if ($wasStaleEscape) {
            // Unblock Order Plan sizing only. NLV already includes cash — do not
            // copy AF into NLV or write an Alpha snapshot (that spiked YTD to 26%).
            $user->forceFill([
                'ibkr_available_funds' => $amount,
                'ibkr_settled_cash' => $amount,
                'ibkr_data_stale' => false,
                'ibkr_last_success_at' => now(),
                'ibkr_last_error' => null,
            ])->save();

            $user = $user->fresh() ?? $user;
            $this->ibkrStale = false;
        } else {
            $snapshots->recordSnapshot($user, $amount, $snapshots->alphaTrackerSessionDate());
        }

        if ($wasStaleEscape) {
            $pie = app(SmartAllocationService::class)->resolveSizingBankroll($user->fresh() ?? $user);

            FilamentNotifier::send(
                title: 'IBKR override opgeslagen',
                body: sprintf(
                    'Sizing is weer vrijgegeven (deployable ≈ $%s). Draai later vestix:sync-ibkr voor verse Flex-data.',
                    number_format($pie, 2),
                ),
            );

            $this->redirect(Dashboard::getUrl(), navigate: true);

            return;
        }

        FilamentNotifier::send(
            title: 'Bankroll bijgewerkt',
            body: 'Je wekelijkse snapshot is opgeslagen. Alpha Tracker is bijgewerkt.',
        );

        $this->dispatch('$refresh');
    }
}
