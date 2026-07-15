<?php

namespace App\Listeners;

use App\Enums\UserAccountCreatedSource;
use App\Events\UserAccountCreated;
use App\Models\User;
use DutchCodingCompany\FilamentSocialite\Events\Registered;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser;

class DispatchUserAccountCreatedFromGoogleRegistration
{
    public function handle(Registered $event): void
    {
        $socialiteUser = $event->socialiteUser;

        if (! $socialiteUser instanceof FilamentSocialiteUser) {
            return;
        }

        $user = $socialiteUser->getUser();

        if (! $user instanceof User) {
            return;
        }

        UserAccountCreated::dispatch($user, UserAccountCreatedSource::GoogleRegistration);
    }
}
