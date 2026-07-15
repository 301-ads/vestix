<?php

namespace App\Filament\Pages;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Models\UserAlertPreference;
use App\Services\BankrollSnapshotService;
use App\Services\TelegramLinkService;
use App\Support\PositionSizing;
use App\Support\TelegramNotifier;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EditUserProfile extends EditProfile
{
    private const TELEGRAM_BRAND = '#26A5E4';

    private bool $shouldRecordBankrollSnapshot = false;

    public function form(Schema $schema): Schema
    {
        $alertGroups = AlertEventType::labeledGroups();

        return $schema
            ->components([
                Tabs::make()
                    ->persistTabInQueryString('tab')
                    ->extraAttributes(['class' => 'vestix-profile-page'])
                    ->tabs([
                        Tab::make('Algemeen & Beveiliging')
                            ->icon(Heroicon::OutlinedUser)
                            ->schema([
                                Section::make('Account')
                                    ->compact()
                                    ->schema([
                                        $this->getNameFormComponent(),
                                        $this->getEmailFormComponent(),
                                    ]),
                                Section::make('Privacy')
                                    ->compact()
                                    ->description('Bepaal of andere gebruikers je kunnen vinden bij het aanmaken of beheren van squads.')
                                    ->schema([
                                        Toggle::make('is_discoverable')
                                            ->label('Zichtbaar voor squad-uitnodigingen')
                                            ->helperText('Uitgeschakeld: je verschijnt niet in de gebruikerszoekfunctie. Je kunt nog wel via e-mail worden uitgenodigd.')
                                            ->default(true),
                                    ]),
                                Section::make('Beveiliging')
                                    ->compact()
                                    ->schema([
                                        $this->getPasswordFormComponent(),
                                        $this->getPasswordConfirmationFormComponent(),
                                        $this->getCurrentPasswordFormComponent(),
                                    ]),
                            ]),
                        Tab::make('Trading Voorkeuren')
                            ->icon(Heroicon::OutlinedCog6Tooth)
                            ->schema([
                                Section::make('Position sizing')
                                    ->compact()
                                    ->description('Bankroll en standaard risico-limiet voor de waakhond op scouts.')
                                    ->schema([
                                        TextInput::make('trading_bankroll')
                                            ->label('Mijn Totale Trading Kapitaal (Bankroll)')
                                            ->numeric()
                                            ->prefix('$')
                                            ->minValue(0.01)
                                            ->helperText('Kopieer het totaal uit je broker (Revolut: Beleggingsrekening). Update wekelijks of maandelijks.'),
                                        ToggleButtons::make('default_risk_percent')
                                            ->label('Standaard risico-niveau')
                                            ->options(PositionSizing::riskPercentOptions())
                                            ->default('1')
                                            ->formatStateUsing(fn (mixed $state): string => PositionSizing::normalizeRiskPercentOptionKey($state) ?? '1')
                                            ->dehydrateStateUsing(fn (mixed $state): ?float => filled($state) ? (float) $state : null)
                                            ->inline()
                                            ->required(),
                                    ]),
                                Section::make('Mijn broker')
                                    ->compact()
                                    ->description('Bepaalt hoe Vestix je begeleidt bij het uitvoeren van orders.')
                                    ->schema([
                                        ToggleButtons::make('primary_broker')
                                            ->label('Broker')
                                            ->options(Broker::options())
                                            ->default(Broker::Revolut->value)
                                            ->inline()
                                            ->required()
                                            ->helperText('Revolut toont bevestigingsflows in de app. Geen/handmatig toont Limit Sell-instructies op het dashboard.'),
                                    ]),
                            ]),
                        Tab::make('Telegram & Alerts')
                            ->icon(Heroicon::OutlinedBell)
                            ->schema([
                                Section::make('Telegram alerts')
                                    ->compact()
                                    ->extraAttributes(['class' => 'vestix-profile-telegram-section'])
                                    ->schema([
                                        Grid::make(['default' => 1, 'md' => 2])
                                            ->extraAttributes(['class' => 'vestix-profile-telegram-row'])
                                            ->schema([
                                                Placeholder::make('telegram_status')
                                                    ->hiddenLabel()
                                                    ->inlineLabel(false)
                                                    ->content(function (): HtmlString {
                                                        $user = $this->getUser();

                                                        if ($user->hasTelegramConnection()) {
                                                            return new HtmlString(
                                                                '<span class="text-success-600 dark:text-success-400">Gekoppeld</span> — alerts gaan naar je privé Telegram-chat.'
                                                            );
                                                        }

                                                        return new HtmlString(
                                                            'Nog niet gekoppeld. Klik op <strong>Koppel Telegram</strong> en druk daarna op <strong>Start</strong> in Telegram.'
                                                        );
                                                    }),
                                                Actions::make([
                                                    $this->connectTelegramAction(),
                                                    $this->disconnectTelegramAction(),
                                                    $this->testTelegramAction(),
                                                ])
                                                    ->alignment(Alignment::Start)
                                                    ->verticalAlignment(VerticalAlignment::Center),
                                            ]),
                                    ]),
                                Section::make('Alert voorkeuren')
                                    ->compact()
                                    ->description('Kies welke Set & Forget meldingen je ontvangt.')
                                    ->schema([
                                        Grid::make(2)
                                            ->extraAttributes(['class' => 'vestix-profile-digest-row'])
                                            ->schema([
                                                Toggle::make('alert_events_digest')
                                                    ->label(AlertEventType::DailyDigest->label())
                                                    ->inlineLabel(false)
                                                    ->default(true)
                                                    ->afterStateHydrated(function (Toggle $component): void {
                                                        $component->state($this->hasAlertEvent(AlertEventType::DailyDigest));
                                                    }),
                                                TimePicker::make('daily_digest_time')
                                                    ->label('Digest tijd')
                                                    ->inlineLabel(false)
                                                    ->seconds(false)
                                                    ->default('21:45')
                                                    ->afterStateHydrated(function (TimePicker $component): void {
                                                        $preference = $this->getTelegramAlertPreference();

                                                        if ($preference?->daily_digest_time) {
                                                            $component->state($preference->daily_digest_time);
                                                        }
                                                    }),
                                            ]),
                                        Grid::make(['default' => 1, 'lg' => 2])
                                            ->schema([
                                                Section::make('Order & Winst Executie')
                                                    ->compact()
                                                    ->extraAttributes(['class' => 'vestix-profile-alert-category'])
                                                    ->schema([
                                                        $this->alertEventCheckboxList('alert_events_order', $alertGroups['order']),
                                                    ]),
                                                Section::make('Pre-Market & Kansen')
                                                    ->compact()
                                                    ->extraAttributes(['class' => 'vestix-profile-alert-category'])
                                                    ->schema([
                                                        $this->alertEventCheckboxList('alert_events_premarket', $alertGroups['premarket']),
                                                    ]),
                                                Section::make('Risico & Earnings Waarschuwingen')
                                                    ->compact()
                                                    ->columnSpanFull()
                                                    ->extraAttributes(['class' => 'vestix-profile-alert-category'])
                                                    ->schema([
                                                        $this->alertEventCheckboxList('alert_events_risk', $alertGroups['risk']),
                                                    ]),
                                            ]),
                                        Section::make('Squad')
                                            ->compact()
                                            ->extraAttributes(['class' => 'vestix-profile-alert-category'])
                                            ->schema([
                                                $this->alertEventCheckboxList('alert_events_squad', $alertGroups['squad']),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function alertEventCheckboxList(string $name, array $options): CheckboxList
    {
        $categoryKeys = array_keys($options);

        return CheckboxList::make($name)
            ->hiddenLabel()
            ->inlineLabel(false)
            ->options($options)
            ->columns(1)
            ->afterStateHydrated(function (CheckboxList $component) use ($categoryKeys): void {
                UserAlertPreference::ensureDefaultsForUser($this->getUser());
                $activeEvents = $this->getTelegramAlertPreference()?->active_events ?? AlertEventType::defaults();
                $component->state(array_values(array_intersect($activeEvents, $categoryKeys)));
            });
    }

    private function hasAlertEvent(AlertEventType $event): bool
    {
        UserAlertPreference::ensureDefaultsForUser($this->getUser());
        $activeEvents = $this->getTelegramAlertPreference()?->active_events ?? AlertEventType::defaults();

        return in_array($event->value, $activeEvents, true);
    }

    private function getTelegramAlertPreference(): ?UserAlertPreference
    {
        return $this->getUser()
            ->alertPreferences()
            ->where('channel_type', 'telegram')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function mergeAlertEvents(array $data): array
    {
        $merged = array_merge(
            $data['alert_events_order'] ?? [],
            $data['alert_events_premarket'] ?? [],
            $data['alert_events_risk'] ?? [],
            $data['alert_events_squad'] ?? [],
        );

        if ($data['alert_events_digest'] ?? false) {
            $merged[] = AlertEventType::DailyDigest->value;
        }

        return array_values(array_unique($merged));
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $hasAlertData = isset($data['alert_events_order'])
            || isset($data['alert_events_premarket'])
            || isset($data['alert_events_risk'])
            || isset($data['alert_events_squad'])
            || array_key_exists('alert_events_digest', $data)
            || isset($data['daily_digest_time']);

        if ($hasAlertData) {
            UserAlertPreference::ensureDefaultsForUser($this->getUser());

            $preference = $this->getTelegramAlertPreference();

            if ($preference) {
                $preference->update([
                    'active_events' => $this->mergeAlertEvents($data),
                    'daily_digest_time' => $data['daily_digest_time'] ?? '21:45:00',
                ]);
            }

            unset(
                $data['alert_events_order'],
                $data['alert_events_premarket'],
                $data['alert_events_risk'],
                $data['alert_events_squad'],
                $data['alert_events_digest'],
                $data['daily_digest_time'],
            );
        }

        if (array_key_exists('trading_bankroll', $data) && filled($data['trading_bankroll'])) {
            $this->shouldRecordBankrollSnapshot = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->shouldRecordBankrollSnapshot) {
            return;
        }

        $user = $this->getUser()->fresh();
        $bankroll = $user?->trading_bankroll;

        if ($bankroll === null || (float) $bankroll <= 0) {
            return;
        }

        app(BankrollSnapshotService::class)->recordSnapshot($user, (float) $bankroll);
    }

    protected function connectTelegramAction(): Action
    {
        return Action::make('connect_telegram')
            ->label('Koppel Telegram')
            ->icon('heroicon-o-paper-airplane')
            ->color(Color::hex(self::TELEGRAM_BRAND))
            ->visible(fn (): bool => ! $this->getUser()->hasTelegramConnection())
            ->url(fn (TelegramLinkService $telegramLink): ?string => $telegramLink->linkUrlFor($this->getUser()))
            ->openUrlInNewTab()
            ->disabled(fn (TelegramLinkService $telegramLink): bool => $telegramLink->linkUrlFor($this->getUser()) === null);
    }

    protected function disconnectTelegramAction(): Action
    {
        return Action::make('disconnect_telegram')
            ->label('Ontkoppel Telegram')
            ->color('danger')
            ->visible(fn (): bool => $this->getUser()->hasTelegramConnection())
            ->requiresConfirmation()
            ->modalHeading('Telegram ontkoppelen?')
            ->modalDescription('Je ontvangt geen persoonlijke Vestix-alerts meer in Telegram.')
            ->action(function (): void {
                $this->getUser()->clearTelegramConnection();

                Notification::make()
                    ->title('Telegram ontkoppeld')
                    ->success()
                    ->send();
            });
    }

    protected function testTelegramAction(): Action
    {
        return Action::make('test_telegram')
            ->label('Test Telegram')
            ->color(Color::hex(self::TELEGRAM_BRAND))
            ->visible(fn (): bool => $this->getUser()->hasTelegramConnection())
            ->action(function (): void {
                $user = $this->getUser();

                if (TelegramNotifier::sendToUser($user, 'Vestix testbericht — je Telegram-koppeling werkt.')) {
                    Notification::make()
                        ->title('Testbericht verstuurd')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Telegram mislukt')
                    ->body('Controleer je koppeling of vraag de beheerder om de bot-configuratie te controleren.')
                    ->warning()
                    ->send();
            });
    }
}
