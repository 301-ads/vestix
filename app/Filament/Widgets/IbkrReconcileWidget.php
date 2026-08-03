<?php

namespace App\Filament\Widgets;

use App\Models\Position;
use App\Services\Ibkr\IbkrPositionReconciler;
use App\Support\Entitlements;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class IbkrReconcileWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.ibkr-reconcile-widget';

    protected static ?int $sort = 1;

    /**
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        if ($user === null || ! Entitlements::allows($user, Entitlements::FEATURE_IBKR_RECONCILE)) {
            return false;
        }

        if ($user->ibkr_last_success_at === null) {
            return false;
        }

        return app(IbkrPositionReconciler::class)->mismatchesForUserId((int) $user->id)->isNotEmpty();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getMismatchesProperty(): Collection
    {
        $userId = auth()->id() ?? 0;

        return app(IbkrPositionReconciler::class)->mismatchesForUserId($userId);
    }

    public function boot(): void
    {
        // Unique per-row action names must be re-cached on every Livewire request.
        foreach ($this->mismatches as $index => $mismatch) {
            $this->actionForMismatch($index, $mismatch);
        }
    }

    /**
     * @param  array<string, mixed>  $mismatch
     */
    public function actionForMismatch(int $index, array $mismatch): ?Action
    {
        if (($mismatch['type'] ?? null) !== 'qty_drift' || blank($mismatch['position_id'] ?? null)) {
            return null;
        }

        return $this->cacheAction($this->acceptQtyAction($index, $mismatch));
    }

    /**
     * @param  array<string, mixed>  $mismatch
     */
    public function acceptQtyAction(int $index, array $mismatch): Action
    {
        return Action::make('acceptQty_'.$index)
            ->label('Neem IBKR-aantal over')
            ->color('primary')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading('IBKR-aantal overnemen')
            ->modalDescription($mismatch['message'] ?? '')
            ->action(function () use ($mismatch): void {
                $position = Position::query()->find($mismatch['position_id'] ?? 0);

                if ($position === null || ! $position->isOwnedBy(auth()->user())) {
                    Notification::make()->title('Positie niet gevonden')->danger()->send();

                    return;
                }

                app(IbkrPositionReconciler::class)->acceptQuantity(
                    $position,
                    (float) ($mismatch['ibkr_qty'] ?? 0),
                );

                Notification::make()
                    ->title('Aantal bijgewerkt')
                    ->body("{$position->ticker} volgt nu het aantal uit IBKR Flex.")
                    ->success()
                    ->send();
            });
    }
}
