<?php

namespace App\Filament\Pages;

use App\Services\TelegramLinkService;
use App\Support\TelegramNotifier;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\Placeholder;
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
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
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
