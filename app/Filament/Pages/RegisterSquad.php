<?php

namespace App\Filament\Pages;

use App\Enums\SquadRole;
use App\Filament\Forms\Components\UserPicker;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadManagementService;
use App\Services\SquadPermissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
                        UserPicker::make('member_ids')
                            ->label('Leden uitnodigen')
                            ->helperText('Zoek op naam of e-mail. Alleen zichtbare gebruikers verschijnen.'),
                        Select::make('default_member_role')
                            ->label('Standaard rol voor uitgenodigde leden')
                            ->options(SquadRole::options())
                            ->default(SquadRole::Sniper->value)
                            ->required(),
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

            $memberIds = collect($data['member_ids'] ?? [])
                ->filter(fn (mixed $id): bool => filled($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if ($memberIds->isNotEmpty()) {
                $role = SquadRole::from($data['default_member_role'] ?? SquadRole::Sniper->value);
                $management = app(SquadManagementService::class);

                foreach ($memberIds as $memberId) {
                    $member = User::query()->find($memberId);

                    if ($member === null) {
                        continue;
                    }

                    $management->addMember($squad, $member->email, $role);
                }
            }
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
