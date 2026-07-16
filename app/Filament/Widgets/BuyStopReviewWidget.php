<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Support\FilamentPolling;
use App\Support\SetupGradeDisplay;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class BuyStopReviewWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.buy-stop-review-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function boot(): void
    {
        $this->cacheAction($this->rolloverBuyStopAction());
        $this->cacheAction($this->editBuyStopEntryAction());
        $this->cacheAction($this->cancelBuyStopSetupAction());
    }

    public static function canView(): bool
    {
        $userId = auth()->id();

        if ($userId === null) {
            return false;
        }

        return Position::requiringBuyStopReviewForUser($userId)->isNotEmpty();
    }

    public function getPollingInterval(): ?string
    {
        return FilamentPolling::INTERVAL;
    }

    public function getDefaultActionRecord(Action $action): ?Model
    {
        $arguments = $action->getArguments();
        $key = $arguments['record'] ?? $arguments['recordKey'] ?? null;

        return $this->resolveReviewPosition($key);
    }

    /**
     * @return EloquentCollection<int, Position>
     */
    public function getReviewPositionsProperty(): EloquentCollection
    {
        $userId = auth()->id() ?? 0;

        return Position::requiringBuyStopReviewForUser($userId);
    }

    public function formatInstruction(Position $record): string
    {
        return 'Order is gisteren niet geraakt. Is de setup vandaag nog geldig op basis van de nieuwe dagkaars?';
    }

    public function formatValidationHintHtml(Position $record): ?HtmlString
    {
        $hint = $record->buyStopReviewValidationHint();

        if ($hint === null) {
            $grade = SetupGradeDisplay::label($record);

            if ($grade === null) {
                return null;
            }

            return new HtmlString(sprintf(
                'Huidige setup: <span class="font-medium">%s</span>.',
                e($grade),
            ));
        }

        return new HtmlString('<span class="font-medium text-warning-600 dark:text-warning-400">'.e($hint).'</span>');
    }

    /**
     * @return array<int, Action>
     */
    public function actionsForPosition(Position $position): array
    {
        return [
            $this->cacheAction($this->rolloverBuyStopAction())(['record' => $position->getKey()])->record($position),
            $this->cacheAction($this->editBuyStopEntryAction())(['record' => $position->getKey()])->record($position),
            $this->cacheAction($this->cancelBuyStopSetupAction())(['record' => $position->getKey()])->record($position),
        ];
    }

    public function rolloverBuyStopAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::rolloverBuyStop());
    }

    public function editBuyStopEntryAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::editBuyStopEntry(ScoutResource::class));
    }

    public function cancelBuyStopSetupAction(): Action
    {
        return $this->configureRecordAction(PositionRecordActions::cancelBuyStopSetup());
    }

    private function configureRecordAction(Action $action): Action
    {
        return $action
            ->resolveRecordUsing(function (array $arguments) use ($action): ?Position {
                $key = $arguments['key']
                    ?? $action->getArguments()['record']
                    ?? $action->getArguments()['recordKey']
                    ?? null;

                return $this->resolveReviewPosition($key);
            })
            ->before(function (Action $action): void {
                $record = $this->getDefaultActionRecord($action);

                if ($record instanceof Position) {
                    $action->record($record);
                }
            });
    }

    private function resolveReviewPosition(mixed $key): ?Position
    {
        if ($key === null || $key === '') {
            return null;
        }

        if ($key instanceof Position) {
            return $key;
        }

        return Position::query()
            ->forUser(auth()->id() ?? 0)
            ->requiringBuyStopReview()
            ->find($key);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'heading' => $this->buildHeading($this->reviewPositions->count()),
        ];
    }

    private function buildHeading(int $count): string|HtmlString
    {
        if ($count === 0) {
            return 'Buy-stop review';
        }

        return new HtmlString(
            '<span class="inline-flex flex-wrap items-center gap-2">'
            .'<span>Buy-stop review</span>'
            .'<span class="inline-flex items-center rounded-md bg-warning-500/10 px-2 py-0.5 text-xs font-medium text-warning-400 ring-1 ring-inset ring-warning-500/20">'
            .$count
            .'</span>'
            .'</span>'
        );
    }
}
