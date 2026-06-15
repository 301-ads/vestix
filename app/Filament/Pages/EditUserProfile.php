<?php

namespace App\Filament\Pages;

use App\Support\TelegramNotifier;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;

class EditUserProfile extends EditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                TextInput::make('telegram_chat_id')
                    ->label('Telegram Chat ID')
                    ->helperText('Je persoonlijke chat ID voor liquidatie- en radar-alerts.')
                    ->maxLength(255),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('test_telegram')
                ->label('Test Telegram')
                ->color('gray')
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
                        ->body('Controleer je chat ID en bot-token in .env.')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
