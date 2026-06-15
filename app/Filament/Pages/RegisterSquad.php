<?php

namespace App\Filament\Pages;

use App\Enums\SquadRole;
use App\Models\Squad;
use App\Services\SquadPermissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Throwable;

class RegisterSquad extends Page
{
    use CanUseDatabaseTransactions;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static ?string $navigationLabel = 'Nieuwe squad';

    protected static ?string $title = 'Nieuwe squad';

    protected static ?string $slug = 'squads/create';

    protected static string|\UnitEnum|null $navigationGroup = 'Squads';

    protected static ?int $navigationSort = 4;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Squad aanmaken')
                    ->schema([
                        TextInput::make('name')
                            ->label('Squad naam')
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('register-squad-form')
                    ->livewireSubmitHandler('create')
                    ->footer([
                        Actions::make([
                            Action::make('create')
                                ->label('Squad aanmaken')
                                ->submit('create'),
                        ]),
                    ]),
            ]);
    }

    public function create(): void
    {
        try {
            $this->beginDatabaseTransaction();

            $data = $this->form->getState();

            $squad = Squad::query()->create([
                'name' => $data['name'],
                'owner_id' => auth()->id(),
            ]);

            $squad->users()->attach(auth()->id());

            app(SquadPermissionService::class)->assignRole(
                auth()->user(),
                $squad,
                SquadRole::Commander,
            );
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction() ?
                $this->rollBackDatabaseTransaction() :
                $this->commitDatabaseTransaction();

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        Notification::make()
            ->title('Squad aangemaakt')
            ->success()
            ->send();

        $this->redirect(ManageSquadSettings::getUrl(['squad' => $squad->id]));
    }
}
