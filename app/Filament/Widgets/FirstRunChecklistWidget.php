<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\EditUserProfile;
use App\Support\FirstRunChecklist;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;

class FirstRunChecklistWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.first-run-checklist-widget';

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    /**
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && FirstRunChecklist::shouldShow($user);
    }

    public function boot(): void
    {
        $this->openProfileAction();
        $this->dismissChecklistAction();
    }

    /**
     * @return array{
     *     completed: bool,
     *     dismissed: bool,
     *     steps: array<string, array{key: string, label: string, done: bool, hint: string}>,
     *     done_count: int,
     *     total: int
     * }
     */
    public function getStatusProperty(): array
    {
        return FirstRunChecklist::status(auth()->user());
    }

    public function dismissChecklistAction(): Action
    {
        return $this->cacheAction(
            Action::make('dismissChecklist')
                ->label('Later')
                ->color('gray')
                ->link()
                ->action(function (): void {
                    FirstRunChecklist::dismiss(auth()->user());

                    Notification::make()
                        ->title('Checklist verborgen')
                        ->body('Je vindt setup-opties altijd onder Profiel.')
                        ->success()
                        ->send();
                }),
        );
    }

    public function openProfileAction(): Action
    {
        return $this->cacheAction(
            Action::make('openProfile')
                ->label('Naar profiel')
                ->color('primary')
                ->url(EditUserProfile::getUrl()),
        );
    }
}
