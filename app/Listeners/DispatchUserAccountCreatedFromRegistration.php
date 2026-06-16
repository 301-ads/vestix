<?php

namespace App\Listeners;

use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Models\User;
use Filament\Auth\Events\Registered;

class DispatchUserAccountCreatedFromRegistration
{
    public function handle(Registered $event): void
    {
        $user = $event->getUser();

        if (! $user instanceof User) {
            return;
        }

        UserAccountCreated::dispatch($user, UserAccountCreatedSource::Registration);
    }
}
