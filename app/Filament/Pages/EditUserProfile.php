<?php

namespace App\Filament\Pages;

use App\Enums\AlertEventType;
use App\Enums\BankrollCashflowType;
use App\Enums\Broker;
use App\Models\BankrollCashflow;
use App\Models\UserAlertPreference;
use App\Services\BankrollCashflowService;
use App\Services\BankrollSnapshotService;
use App\Services\Ibkr\IbkrSyncHealth;
use App\Services\TelegramLinkService;
use App\Support\PositionSizing;
use App\Support\TelegramNotifier;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
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
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class EditUserProfile extends EditProfile
{
    private const TELEGRAM_BRAND = '#26A5E4';

    private bool $shouldRecordBankrollSnapshot = false;

    public function boot(): void
    {
        $this->cacheAction($this->editCashflowAction());
        $this->cacheAction($this->deleteCashflowAction());
    }

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
                                    ->description('NLV voor Alpha · Cash/AF voor Order Plan sizing. Zelfde splitsing als in IBKR/TradingView.')
                                    ->schema([
                                        Placeholder::make('ibkr_sync_status')
                                            ->label('IBKR sync')
                                            ->content(fn (): HtmlString => $this->ibkrSyncStatusHtml()),
                                        TextInput::make('trading_bankroll')
                                            ->label('Net Liquidation (NLV)')
                                            ->numeric()
                                            ->prefix('$')
                                            ->minValue(0.01)
                                            ->helperText(fn (): string => $this->tradingBankrollHelperText()),
                                        TextInput::make('ibkr_available_funds')
                                            ->label('Cash / Available Funds')
                                            ->numeric()
                                            ->prefix('$')
                                            ->minValue(0)
                                            ->helperText('Voor Order Plan / Smart Sizing. Op een cash-account gelijk aan Cash in IBKR. Settled Cash wordt hieraan gelijk gezet.')
                                            ->visible(fn (): bool => $this->getUser()->ibkr_last_success_at !== null
                                                || (string) config('vestix.ibkr.reader', 'stub') === 'flex'
                                                || $this->getUser()->primary_broker === Broker::Ibkr),
                                        Placeholder::make('ibkr_deployable')
                                            ->label('Deployable')
                                            ->content(fn (): string => $this->ibkrDeployableSummary())
                                            ->visible(fn (): bool => $this->getUser()->ibkr_last_success_at !== null
                                                || $this->getUser()->ibkr_available_funds !== null
                                                || $this->getUser()->ibkr_settled_cash !== null),
                                        DatePicker::make('baseline_date')
                                            ->label('Alpha startdatum')
                                            ->native(false)
                                            ->displayFormat('d-m-Y')
                                            ->format('Y-m-d')
                                            ->helperText('Wordt automatisch gezet bij je eerste storting. Snapshots/cashflows vóór deze datum tellen niet mee.'),
                                        ToggleButtons::make('default_risk_percent')
                                            ->label('Standaard risico-niveau')
                                            ->options(PositionSizing::riskPercentOptions())
                                            ->default('1')
                                            ->formatStateUsing(fn (mixed $state): string => PositionSizing::normalizeRiskPercentOptionKey($state) ?? '1')
                                            ->dehydrateStateUsing(fn (mixed $state): ?float => filled($state) ? (float) $state : null)
                                            ->inline()
                                            ->required(),
                                    ]),
                                Section::make('Kapitaalbewegingen')
                                    ->compact()
                                    ->description('IBKR Flex importeert stortingen/opnames automatisch. Alleen handmatig registreren als Flex ze mist — niet opnieuw invoeren (dat maakt Alpha dubbel).')
                                    ->schema([
                                        Actions::make([
                                            $this->recordCashflowAction(),
                                        ])
                                            ->alignment(Alignment::Start)
                                            ->verticalAlignment(VerticalAlignment::Start)
                                            ->extraAttributes(['class' => 'vestix-cashflows-toolbar']),
                                        View::make('filament.pages.cashflows-table')
                                            ->viewData(fn (): array => [
                                                'cashflows' => app(BankrollCashflowService::class)
                                                    ->recentForUser($this->getUser(), 50),
                                            ]),
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
                                            ->helperText('Nieuwe scouts krijgen deze broker. Bestaande posities behouden hun oorspronkelijke tag. Revolut toont bevestigingsflows; IBKR gebruikt bracket orders; Geen/handmatig toont Limit Sell-instructies.'),
                                        Toggle::make('is_short_enabled')
                                            ->label('Activeer Short-Selling')
                                            ->helperText('Alleen voor goedgekeurde margin-accounts. Zet dit uit als je broker shorts niet toestaat — de Short-optie verdwijnt dan uit Scout toevoegen.')
                                            ->inline(false),
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
                                                    ->extraAttributes(['class' => 'vestix-profile-alert-category'])
                                                    ->schema([
                                                        $this->alertEventCheckboxList('alert_events_risk', $alertGroups['risk']),
                                                    ]),
                                            ]),
                                        Section::make('Social & Squads')
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
            $data['ibkr_net_liquidation'] = round((float) $data['trading_bankroll'], 2);
        }

        if (array_key_exists('ibkr_available_funds', $data) && filled($data['ibkr_available_funds'])) {
            // Cash account: treat Available Funds as deployable; keep Settled in sync for min(AF, settled).
            $deployable = round((float) $data['ibkr_available_funds'], 2);
            $data['ibkr_available_funds'] = $deployable;
            $data['ibkr_settled_cash'] = $deployable;
        }

        if (array_key_exists('baseline_date', $data)) {
            $data['baseline_date'] = filled($data['baseline_date'] ?? null)
                ? Carbon::parse((string) $data['baseline_date'])->toDateString()
                : null;
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

    /**
     * @return array<int, Component>
     */
    protected function cashflowFormSchema(): array
    {
        return [
            ToggleButtons::make('type')
                ->label('Type')
                ->options(BankrollCashflowType::options())
                ->inline()
                ->required(),
            TextInput::make('amount')
                ->label('Bedrag')
                ->numeric()
                ->prefix('$')
                ->minValue(0.01)
                ->required(),
            DatePicker::make('occurred_on')
                ->label('Datum')
                ->native(false)
                ->displayFormat('d-m-Y')
                ->format('Y-m-d')
                ->required(),
            Textarea::make('note')
                ->label('Notitie')
                ->rows(2)
                ->maxLength(255),
        ];
    }

    protected function recordCashflowAction(): Action
    {
        return Action::make('record_cashflow')
            ->label('Registreer storting / opname')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('primary')
            ->modalHeading('Kapitaalbeweging registreren')
            ->modalDescription('Alleen nodig als IBKR Flex een storting mist. Update daarna ook je NLV naar het echte saldo.')
            ->form($this->cashflowFormSchema())
            ->fillForm([
                'type' => BankrollCashflowType::Deposit->value,
                'amount' => null,
                'occurred_on' => now()->toDateString(),
                'note' => null,
            ])
            ->action(function (array $data): void {
                app(BankrollCashflowService::class)->record(
                    $this->getUser(),
                    BankrollCashflowType::from((string) $data['type']),
                    (float) $data['amount'],
                    Carbon::parse((string) $data['occurred_on'])->startOfDay(),
                    isset($data['note']) ? (string) $data['note'] : null,
                );

                Notification::make()
                    ->title('Kapitaalbeweging opgeslagen')
                    ->body('Zet je NLV gelijk aan je actuele IBKR Net Liquidation zodat de Alpha Tracker klopt.')
                    ->success()
                    ->send();

                $this->fillForm();
            });
    }

    protected function editCashflowAction(): Action
    {
        return Action::make('edit_cashflow')
            ->label('Wijzig')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('Kapitaalbeweging wijzigen')
            ->form($this->cashflowFormSchema())
            ->fillForm(function (array $arguments): array {
                $cashflow = $this->resolveCashflow($arguments['cashflow'] ?? null);

                if ($cashflow === null) {
                    return [];
                }

                return [
                    'type' => $cashflow->type->value,
                    'amount' => (float) $cashflow->amount,
                    'occurred_on' => $cashflow->occurred_on->toDateString(),
                    'note' => $cashflow->note,
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $cashflow = $this->resolveCashflow($arguments['cashflow'] ?? null);

                if ($cashflow === null) {
                    return;
                }

                app(BankrollCashflowService::class)->update(
                    $this->getUser(),
                    $cashflow->id,
                    BankrollCashflowType::from((string) $data['type']),
                    (float) $data['amount'],
                    Carbon::parse((string) $data['occurred_on'])->startOfDay(),
                    isset($data['note']) ? (string) $data['note'] : null,
                );

                Notification::make()
                    ->title('Kapitaalbeweging bijgewerkt')
                    ->success()
                    ->send();

                $this->fillForm();
            });
    }

    protected function deleteCashflowAction(): Action
    {
        return Action::make('delete_cashflow')
            ->label('Verwijder')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Kapitaalbeweging verwijderen?')
            ->action(function (array $arguments): void {
                $cashflow = $this->resolveCashflow($arguments['cashflow'] ?? null);

                if ($cashflow === null) {
                    return;
                }

                app(BankrollCashflowService::class)->deleteForUser($this->getUser(), $cashflow->id);

                Notification::make()
                    ->title('Kapitaalbeweging verwijderd')
                    ->success()
                    ->send();

                $this->fillForm();
            });
    }

    protected function resolveCashflow(mixed $id): ?BankrollCashflow
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->getUser()
            ->bankrollCashflows()
            ->whereKey($id)
            ->first();
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

    protected function ibkrSyncStatusHtml(): HtmlString
    {
        $user = $this->getUser();
        $health = app(IbkrSyncHealth::class);

        if ($user->ibkr_last_success_at === null) {
            return new HtmlString(
                '<span class="text-sm text-gray-500 dark:text-gray-400">'
                .'Nog geen IBKR sync. Vul NLV handmatig in, of configureer Flex en run <code>vestix:sync-ibkr</code>.'
                .'</span>',
            );
        }

        $when = $user->ibkr_last_success_at
            ->timezone(config('vestix.bankroll_tracker.timezone', 'Europe/Amsterdam'))
            ->format('d-m-Y H:i');

        if ($health->isStale($user)) {
            $error = filled($user->ibkr_last_error)
                ? e(Str::limit((string) $user->ibkr_last_error, 120))
                : 'geen verse data';

            return new HtmlString(
                '<span class="text-sm font-medium text-danger-600 dark:text-danger-400">'
                ."Stale — laatste succes {$when}. Automatische sizing/orders geblokkeerd ({$error})."
                .'</span>',
            );
        }

        return new HtmlString(
            '<span class="text-sm text-success-600 dark:text-success-400">'
            ."Synced {$when} (base ".e((string) ($user->ibkr_base_currency ?? 'USD')).').'
            .'</span>',
        );
    }

    protected function tradingBankrollHelperText(): string
    {
        if ($this->getUser()->ibkr_last_success_at !== null) {
            return 'Gelijk aan Net Liquidation in IBKR/TradingView (cash + open posities). Voor Alpha Tracker. Wordt door Flex sync bijgewerkt; handmatige override blijft mogelijk.';
        }

        return 'Alleen Interactive Brokers NLV — zonder Revolut/legacy. Update na stortingen en wekelijks voor de Alpha Tracker.';
    }

    protected function ibkrDeployableSummary(): string
    {
        $user = $this->getUser();
        $settled = $user->ibkr_settled_cash !== null ? (float) $user->ibkr_settled_cash : null;
        $available = $user->ibkr_available_funds !== null ? (float) $user->ibkr_available_funds : null;

        if ($settled === null && $available === null) {
            return '—';
        }

        $settledLabel = $settled !== null ? '$'.number_format($settled, 2) : '—';
        $availableLabel = $available !== null ? '$'.number_format($available, 2) : '—';
        $deployable = null;

        if ($settled !== null && $available !== null) {
            $deployable = min($settled, $available);
        } elseif ($settled !== null) {
            $deployable = $settled;
        } else {
            $deployable = $available;
        }

        return sprintf(
            'Settled %s · Available Funds %s → Order Plan gebruikt $%s',
            $settledLabel,
            $availableLabel,
            number_format((float) $deployable, 2),
        );
    }
}
