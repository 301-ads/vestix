<?php

namespace App\Filament\Pages;

use App\Enums\SquadRole;
use App\Models\Squad;
use App\Models\User;
use App\Services\SquadContext;
use App\Services\SquadManagementService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\CanUseDatabaseTransactions;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class ManageSquadSettings extends Page implements HasTable
{
    use CanUseDatabaseTransactions;
    use HasTabs;
    use InteractsWithTable;

    protected static ?string $navigationLabel = 'Squad instellingen';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Squads';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Squad instellingen';

    protected static ?string $slug = 'squad-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?Squad $activeSquad = null;

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    #[Url(as: 'squad')]
    public ?int $squadId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin() || $user->squads()->exists();
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return false;
        }

        return $user->squads()->exists();
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
        $this->resolveActiveSquad();
    }

    public function updatedSquadId(): void
    {
        $this->resolveActiveSquad();
    }

    private function resolveActiveSquad(): void
    {
        $user = auth()->user();

        if ($user === null) {
            $this->activeSquad = null;

            return;
        }

        $this->activeSquad = app(SquadContext::class)->resolveSquadForUser($user, $this->squadId);

        if ($this->activeSquad instanceof Squad) {
            $this->squadId = $this->activeSquad->id;
            $this->form->fill($this->activeSquad->attributesToArray());
        }
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'settings';
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        return [
            'settings' => Tab::make('Instellingen'),
            'members' => Tab::make('Squad leden beheren')
                ->visible(fn (): bool => $this->canManageMembers() || $this->isSuperAdminViewer()),
        ];
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->model($this->activeSquad)
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $squad = $this->activeSquad;

        return $schema
            ->components([
                Section::make('Squad')
                    ->schema([
                        TextInput::make('name')
                            ->label('Squad naam')
                            ->required()
                            ->maxLength(255)
                            ->disabled(fn (): bool => ! $this->canEditSettings()),
                    ])
                    ->visible(fn (): bool => $this->canEditSettings()),
                Section::make('Jouw squad')
                    ->schema([
                        Placeholder::make('overview_name')
                            ->label('Naam')
                            ->content($squad instanceof Squad ? $squad->name : '—'),
                        Placeholder::make('overview_role')
                            ->label('Jouw rol')
                            ->content(function (): string {
                                $role = $this->currentUserRole();

                                return $role ? ucfirst($role) : '—';
                            }),
                        Placeholder::make('overview_members')
                            ->label('Leden')
                            ->content(fn (): string => $squad instanceof Squad
                                ? (string) $squad->users()->count()
                                : '—'),
                    ])
                    ->visible(fn (): bool => ! $this->canEditSettings() && ! $this->isSuperAdminViewer()),
                Section::make('Squad overzicht')
                    ->schema([
                        Placeholder::make('admin_overview_name')
                            ->label('Naam')
                            ->content($squad instanceof Squad ? $squad->name : '—'),
                        Placeholder::make('admin_overview_owner')
                            ->label('Eigenaar')
                            ->content(fn (): string => $squad instanceof Squad
                                ? ($squad->owner?->name ?? '—')
                                : '—'),
                        Placeholder::make('admin_overview_members')
                            ->label('Leden')
                            ->content(fn (): string => $squad instanceof Squad
                                ? (string) $squad->users()->count()
                                : '—'),
                    ])
                    ->visible(fn (): bool => $this->isSuperAdminViewer()),
                Section::make('Rol toewijzing')
                    ->description('Je bent lid van deze squad, maar er is nog geen rol aan je account gekoppeld. Vraag de squad-eigenaar om je rol in te stellen.')
                    ->visible(fn (): bool => ! $this->userHasAssignedRole()),
            ]);
    }

    public function table(Table $table): Table
    {
        /** @var Squad|null $squad */
        $squad = $this->activeSquad;

        return $table
            ->query(
                User::query()
                    ->whereHas('squads', fn (Builder $query) => $query->whereKey($squad?->id))
            )
            ->columns([
                TextColumn::make('name')->label('Naam'),
                TextColumn::make('email')->label('E-mail'),
                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->state(function (User $record) use ($squad): string {
                        if (! $squad instanceof Squad) {
                            return '—';
                        }

                        return ucfirst($this->memberRoleName($squad, $record));
                    }),
                TextColumn::make('owner')
                    ->label('Eigenaar')
                    ->state(fn (User $record): string => $squad instanceof Squad && $squad->owner_id === $record->id
                        ? 'Ja'
                        : '—')
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('change_role')
                    ->label('Rol wijzigen')
                    ->icon('heroicon-o-shield-check')
                    ->visible(fn (): bool => $this->canChangeRole())
                    ->schema([
                        Select::make('role')
                            ->label('Rol')
                            ->options([
                                SquadRole::Commander->value => 'Commander',
                                SquadRole::Sniper->value => 'Sniper',
                                SquadRole::Scout->value => 'Scout',
                            ])
                            ->default(fn (User $record): ?string => $squad instanceof Squad
                                ? $this->memberRoleName($squad, $record)
                                : null)
                            ->required(),
                    ])
                    ->action(function (User $record, array $data, SquadManagementService $management): void {
                        $squad = $this->activeSquad;

                        if (! $squad instanceof Squad) {
                            return;
                        }

                        try {
                            $management->changeMemberRole(
                                $squad,
                                $record,
                                SquadRole::from($data['role']),
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Rol bijgewerkt')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make('remove_member')
                    ->label('Verwijderen')
                    ->modalHeading('Lid verwijderen uit squad')
                    ->modalDescription('Deze gebruiker verliest toegang tot de gedeelde radar en squad-dashboard van deze squad.')
                    ->visible(fn (User $record): bool => $this->canRemoveMember($record))
                    ->action(function (User $record, SquadManagementService $management): void {
                        $squad = $this->activeSquad;

                        if (! $squad instanceof Squad) {
                            return;
                        }

                        try {
                            $management->removeMember($squad, $record);
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Lid verwijderd')
                            ->success()
                            ->send();
                    }),
            ])
            ->headerActions([
                Action::make('add_member')
                    ->label('Lid toevoegen')
                    ->visible(fn (): bool => $this->activeSquad instanceof Squad
                        && auth()->user() !== null
                        && app(SquadContext::class)->userCanInSquad(auth()->user(), $this->activeSquad, 'user.invite'))
                    ->schema([
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->live(onBlur: true),
                        Placeholder::make('existing_account_hint')
                            ->label('')
                            ->content('Bestaand account — alleen rol kiezen en toevoegen.')
                            ->visible(fn (Get $get): bool => filled($get('email'))
                                && User::query()->where('email', strtolower(trim((string) $get('email'))))->exists()),
                        TextInput::make('name')
                            ->label('Naam')
                            ->required(fn (Get $get): bool => filled($get('email'))
                                && ! User::query()->where('email', strtolower(trim((string) $get('email'))))->exists())
                            ->visible(fn (Get $get): bool => filled($get('email'))
                                && ! User::query()->where('email', strtolower(trim((string) $get('email'))))->exists()),
                        TextInput::make('password')
                            ->label('Wachtwoord')
                            ->password()
                            ->minLength(8)
                            ->required(fn (Get $get): bool => filled($get('email'))
                                && ! User::query()->where('email', strtolower(trim((string) $get('email'))))->exists())
                            ->visible(fn (Get $get): bool => filled($get('email'))
                                && ! User::query()->where('email', strtolower(trim((string) $get('email'))))->exists()),
                        Select::make('role')
                            ->options([
                                SquadRole::Commander->value => 'Commander',
                                SquadRole::Sniper->value => 'Sniper',
                                SquadRole::Scout->value => 'Scout',
                            ])
                            ->default(SquadRole::Sniper->value)
                            ->required(),
                    ])
                    ->action(function (array $data, SquadManagementService $management): void {
                        $squad = $this->activeSquad;

                        if (! $squad instanceof Squad) {
                            return;
                        }

                        $email = strtolower(trim($data['email']));
                        $wasExisting = User::query()->where('email', $email)->exists();

                        try {
                            $management->addMember(
                                $squad,
                                $email,
                                SquadRole::from($data['role']),
                                $data['name'] ?? null,
                                $data['password'] ?? null,
                            );
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()
                                ->title($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title($wasExisting ? 'Lid gekoppeld' : 'Account aangemaakt en toegevoegd')
                            ->success()
                            ->send();
                    }),
            ])
            ->paginated(false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                Form::make([EmbeddedSchema::make('form')])
                    ->id('squad-settings-form')
                    ->livewireSubmitHandler('save')
                    ->visible(fn (): bool => ($this->activeTab ?? 'settings') === 'settings')
                    ->footer([
                        Actions::make($this->getFormActions())
                            ->visible(fn (): bool => $this->canEditSettings())
                            ->key('squad-settings-form-actions'),
                    ]),
                EmbeddedTable::make()
                    ->visible(fn (): bool => ($this->activeTab ?? 'settings') === 'members'),
            ]);
    }

    public function save(): void
    {
        if (! $this->canEditSettings() || ! $this->activeSquad instanceof Squad) {
            return;
        }

        try {
            $this->beginDatabaseTransaction();

            $data = $this->form->getState();

            $this->activeSquad->update($data);
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
            ->title('Squad opgeslagen')
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Opslaan')
                ->submit('save')
                ->keyBindings(['mod+s']),
            $this->getDeleteSquadAction(),
        ];
    }

    protected function getDeleteSquadAction(): Action
    {
        return Action::make('delete_squad')
            ->label('Squad verwijderen')
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading('Squad verwijderen')
            ->modalDescription('Alle gedeelde radar-setups worden privé. Leden verliezen toegang tot deze squad. Dit kan niet ongedaan worden gemaakt.')
            ->visible(fn (): bool => $this->activeSquad instanceof Squad
                && auth()->user()?->can('delete', $this->activeSquad) === true)
            ->action(function (SquadManagementService $management): void {
                if (! $this->activeSquad instanceof Squad) {
                    return;
                }

                $squad = $this->activeSquad;
                $user = auth()->user();

                $fallbackSquad = $user?->squads()
                    ->whereKeyNot($squad->id)
                    ->orderBy('squads.id')
                    ->first();

                $management->delete($squad);

                Notification::make()
                    ->title('Squad verwijderd')
                    ->success()
                    ->send();

                if ($fallbackSquad !== null) {
                    $this->redirect(static::getUrl(['squad' => $fallbackSquad->id]));

                    return;
                }

                $this->redirect(RegisterSquad::getUrl());
            });
    }

    public function canEditSettings(): bool
    {
        $squad = $this->activeSquad;
        $user = auth()->user();

        if (! $squad instanceof Squad || $user === null) {
            return false;
        }

        return $user->can('update', $squad);
    }

    public function canManageMembers(): bool
    {
        $squad = $this->activeSquad;
        $user = auth()->user();

        if (! $squad instanceof Squad || $user === null) {
            return false;
        }

        return app(SquadManagementService::class)->canManageMembers($squad, $user);
    }

    public function userHasAssignedRole(): bool
    {
        $squad = $this->activeSquad;
        $user = auth()->user();

        if (! $squad instanceof Squad || $user === null) {
            return false;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);
        $hasRole = $user->roles()->exists();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }

    public function currentUserRole(): ?string
    {
        $squad = $this->activeSquad;
        $user = auth()->user();

        if (! $squad instanceof Squad || $user === null) {
            return null;
        }

        return $this->memberRoleName($squad, $user);
    }

    private function memberRoleName(Squad $squad, User $user): ?string
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($squad->id);
        $role = $user->roles()->value('name');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $role !== null ? (string) $role : null;
    }

    private function canRemoveMember(User $record): bool
    {
        $squad = $this->activeSquad;

        if (! $squad instanceof Squad || auth()->user() === null) {
            return false;
        }

        return app(SquadManagementService::class)->canRemoveMember($squad, auth()->user(), $record);
    }

    private function canChangeRole(): bool
    {
        if ($this->isSuperAdminViewer()) {
            return false;
        }

        if (! $this->canManageMembers() || ! $this->activeSquad instanceof Squad || auth()->user() === null) {
            return false;
        }

        return app(SquadContext::class)->userCanInSquad(auth()->user(), $this->activeSquad, 'role.assign');
    }

    public function isSuperAdminViewer(): bool
    {
        $user = auth()->user();
        $squad = $this->activeSquad;

        if ($user === null || ! $user->isSuperAdmin() || ! $squad instanceof Squad) {
            return false;
        }

        return ! $user->squads()->whereKey($squad->id)->exists();
    }
}
