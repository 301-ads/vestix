<?php

namespace App\Providers;

use App\Events\UserAccountCreated;
use App\Listeners\DispatchUserAccountCreatedFromGoogleRegistration;
use App\Listeners\DispatchUserAccountCreatedFromRegistration;
use App\Listeners\SendNewUserAdminNotification;
use DutchCodingCompany\FilamentSocialite\Events\Registered as SocialiteRegistered;
use Filament\Auth\Events\Registered;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string>> */
    protected $listen = [
        Registered::class => [
            DispatchUserAccountCreatedFromRegistration::class,
        ],
        SocialiteRegistered::class => [
            DispatchUserAccountCreatedFromGoogleRegistration::class,
        ],
        UserAccountCreated::class => [
            SendNewUserAdminNotification::class,
        ],
    ];
}
