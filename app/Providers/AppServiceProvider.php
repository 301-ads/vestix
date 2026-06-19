<?php

namespace App\Providers;

use App\Contracts\DailyBarProvider;
use App\Contracts\QuoteProvider;
use App\Http\Responses\Filament\LoginResponse;
use App\Http\Responses\Filament\RegistrationResponse;
use App\Models\Position;
use App\Models\Squad;
use App\Observers\PositionObserver;
use App\Policies\PositionPolicy;
use App\Policies\SquadPolicy;
use App\Services\FallbackDailyBarProvider;
use App\Services\FallbackQuoteProvider;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse as RegistrationResponseContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(QuoteProvider::class, FallbackQuoteProvider::class);
        $this->app->bind(DailyBarProvider::class, FallbackDailyBarProvider::class);
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(RegistrationResponseContract::class, RegistrationResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Gate::policy(Position::class, PositionPolicy::class);
        Gate::policy(Squad::class, SquadPolicy::class);

        Position::observe(PositionObserver::class);
    }
}
