<?php

namespace App\Filament\Pages;

use App\Enums\AlertEventType;
use App\Models\UserAlertPreference;
use App\Services\TelegramLinkService;
use App\Support\PositionSizing;
use App\Support\TelegramNotifier;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Illuminate\Support\HtmlString;

class EditUserProfile extends EditProfile
{
    private const TELEGRAM_BRAND = '#26A5E4';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                Section::make('Telegram alerts')
                    ->compact()
                    ->schema([
                        Placeholder::make('telegram_status')
                            ->hiddenLabel()
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
                        ]),
                    ]),
                Section::make('Alert voorkeuren')
                    ->compact()
                    ->description('Kies welke Set & Forget meldingen je ontvangt.')
                    ->schema([
                        CheckboxList::make('alert_events')
                            ->label('Meldingen')
                            ->options([
                                AlertEventType::SlCanRaise->value => 'Stop-loss kan verhoogd worden',
                                AlertEventType::FreerideSecured->value => 'Freeride secured (winst veiliggesteld)',
                                AlertEventType::StoppedOut->value => 'Stopped out',
                                AlertEventType::DailyDigest->value => 'Dagelijkse digest (21:45)',
                                AlertEventType::PremarketGapRisk->value => 'Pre-market gap-up waarschuwing (14:30)',
                                AlertEventType::PremarketReclamation->value => 'Pre-market reclamation — herovert SMA 20 (14:30)',
                                AlertEventType::PremarketLanding->value => 'Pre-market landing — nadert SMA 20 (14:30)',
                                AlertEventType::EarningsWarning->value => 'Earnings waarschuwing — 2 dagen voor exit (08:00)',
                                AlertEventType::EarningsActionRequired->value => 'Earnings actie — sluit vóór earnings (15:00)',
                                AlertEventType::SquadCopyAlert->value => 'Squad copy-alerts (Ghost Mode)',
                            ])
                            ->default(AlertEventType::defaults())
                            ->columns(1)
                            ->afterStateHydrated(function (CheckboxList $component): void {
                                UserAlertPreference::ensureDefaultsForUser($this->getUser());
                                $preference = $this->getUser()
                                    ->alertPreferences()
                                    ->where('channel_type', 'telegram')
                                    ->first();

                                $component->state($preference?->active_events ?? AlertEventType::defaults());
                            }),
                        TimePicker::make('daily_digest_time')
                            ->label('Digest tijd')
                            ->seconds(false)
                            ->default('21:45')
                            ->afterStateHydrated(function (TimePicker $component): void {
                                $preference = $this->getUser()
                                    ->alertPreferences()
                                    ->where('channel_type', 'telegram')
                                    ->first();

                                if ($preference?->daily_digest_time) {
                                    $component->state($preference->daily_digest_time);
                                }
                            }),
                    ]),
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
                            ->inline()
                            ->required(),
                    ]),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['alert_events']) || isset($data['daily_digest_time'])) {
            UserAlertPreference::ensureDefaultsForUser($this->getUser());

            $preference = $this->getUser()
                ->alertPreferences()
                ->where('channel_type', 'telegram')
                ->first();

            if ($preference) {
                $preference->update([
                    'active_events' => $data['alert_events'] ?? AlertEventType::defaults(),
                    'daily_digest_time' => $data['daily_digest_time'] ?? '21:45:00',
                ]);
            }

            unset($data['alert_events'], $data['daily_digest_time']);
        }

        return $data;
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
