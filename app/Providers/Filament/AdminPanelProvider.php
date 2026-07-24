<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Pages\Login;
use App\Filament\Auth\Pages\Register;
use App\Filament\Auth\Pages\RequestPasswordReset;
use App\Filament\Auth\Pages\ResetPassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EditUserProfile;
use App\Filament\Pages\ManageSquadSettings;
use App\Filament\Pages\RegisterSquad;
use App\Filament\Pages\SquadLeaderboard;
use App\Filament\Pages\StrategyCoach;
use App\Filament\Resources\Positions\Pages\CreateScout;
use App\Filament\Resources\Positions\Pages\EditPosition;
use App\Filament\Resources\Positions\Pages\EditScout;
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Http\Responses\Filament\AuthRedirectResponse;
use App\Models\User;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Models\Contracts\FilamentSocialiteUser as FilamentSocialiteUserContract;
use DutchCodingCompany\FilamentSocialite\Provider;
use Filament\Enums\GlobalSearchPosition;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Leandrocfe\FilamentApexCharts\FilamentApexChartsPlugin;
use Livewire\Livewire;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset(RequestPasswordReset::class, ResetPassword::class)
            ->profile(EditUserProfile::class, isSimple: false)
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Squads'),
                NavigationGroup::make()
                    ->label('Beheer'),
            ])
            ->brandName('')
            ->brandLogo(asset('images/vestix-logo-dark.svg'))
            ->darkModeBrandLogo(asset('images/vestix-logo-white.svg'))
            ->brandLogoHeight('1.5rem')
            ->favicon(asset('images/favicon.png'))
            ->font('Albert Sans')
            ->colors([
                'primary' => Color::hex('#00D492'),
                'gray' => Color::Zinc,
                'danger' => Color::Rose,
                'info' => Color::Blue,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->sidebarWidth('16.25rem')
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Dark)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                ManageSquadSettings::class,
                RegisterSquad::class,
                SquadLeaderboard::class,
                StrategyCoach::class,
            ])
            ->plugins([
                FilamentApexChartsPlugin::make(),
                FilamentSocialitePlugin::make()
                    ->providers([
                        Provider::make('google')
                            ->label('Doorgaan met Google')
                            ->icon('fab-google'),
                    ])
                    ->rememberLogin(true)
                    ->registration(true)
                    ->userModelClass(User::class)
                    ->createUserUsing(function (
                        string $provider,
                        SocialiteUserContract $oauthUser,
                        FilamentSocialitePlugin $plugin,
                    ): User {
                        return User::query()->create([
                            'name' => $oauthUser->getName() ?? $oauthUser->getEmail(),
                            'email' => $oauthUser->getEmail(),
                            'email_verified_at' => now(),
                            'password' => null,
                        ]);
                    })
                    ->redirectAfterLoginUsing(
                        fn (
                            string $provider,
                            FilamentSocialiteUserContract $socialiteUser,
                            FilamentSocialitePlugin $plugin,
                        ) => app(AuthRedirectResponse::class)->toResponse(request()),
                    ),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                PortfolioExposureWidget::class,
                PositionsRequiringActionWidget::class,
                PortfolioTopFlopWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications()
            ->globalSearch(position: GlobalSearchPosition::Topbar)
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => Blade::render('@livewire(\'execution-plan-panel\')'),
            )
            ->renderHook(
                PanelsRenderHook::AUTH_REGISTER_FORM_AFTER,
                function (): string {
                    $panel = filament()->getCurrentPanel();

                    if (! $panel?->hasPlugin('filament-socialite')) {
                        return '';
                    }

                    /** @var FilamentSocialitePlugin $plugin */
                    $plugin = $panel->getPlugin('filament-socialite');

                    return Blade::render(
                        '<x-filament-socialite::buttons :show-divider="'.($plugin->getShowDivider() ? 'true' : 'false').'" />',
                    );
                },
            )
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): string => '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'."\n"
                    .'<link rel="apple-touch-icon" sizes="180x180" href="'.asset('images/apple-touch-icon.png').'">'."\n"
                    .'<link rel="manifest" href="'.asset('manifest.json').'">'
            )
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
                fn (): string => view('filament.dashboard.market-data-status')->render(),
                scopes: [
                    Dashboard::class,
                ],
            )
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_HEADING_AFTER,
                fn (): string => view('filament.dashboard.market-data-status')->render(),
                scopes: [
                    EditPosition::class,
                    EditScout::class,
                ],
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.hooks.share-card-export')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.hooks.pwa-pull-to-refresh')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.hooks.pwa-webpush')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.hooks.create-saving-overlay', [
                    'message' => 'Scout wordt opgeslagen…',
                ])->render(),
                scopes: [
                    CreateScout::class,
                ],
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                function (): string {
                    $livewire = Livewire::current();

                    if (! is_object($livewire)) {
                        return '';
                    }

                    if (property_exists($livewire, 'pollPositionMarketData') && $livewire->pollPositionMarketData) {
                        return view('filament.hooks.market-data-poll', [
                            'poll' => true,
                            'method' => 'pollPositionMarketDataFetch',
                        ])->render();
                    }

                    if (property_exists($livewire, 'pollTickerMarketData') && $livewire->pollTickerMarketData) {
                        return view('filament.hooks.market-data-poll', [
                            'poll' => true,
                            'method' => 'pollTickerMarketDataFetch',
                        ])->render();
                    }

                    if (property_exists($livewire, 'pollAssetBranding') && $livewire->pollAssetBranding) {
                        return view('filament.hooks.market-data-poll', [
                            'poll' => true,
                            'method' => 'pollAssetBrandingFetch',
                        ])->render();
                    }

                    return '';
                },
            );
    }
}
