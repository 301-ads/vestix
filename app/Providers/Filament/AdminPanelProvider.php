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
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Filament\Widgets\SetupRadarWidget;
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
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
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
            ->brandLogo(fn (): HtmlString => new HtmlString(
                view('components.vestix-wordmark', ['size' => 'md'])->render()
            ))
            ->brandLogoHeight('1.5rem')
            ->favicon(asset('images/favicon.svg'))
            ->font('Inter')
            ->colors([
                'primary' => Color::generatePalette('#00D492'),
                'success' => Color::generatePalette('#00D492'),
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
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                PortfolioExposureWidget::class,
                PositionsRequiringActionWidget::class,
                PortfolioTopFlopWidget::class,
                SetupRadarWidget::class,
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
                PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
                fn (): string => view('filament.dashboard.market-data-status')->render(),
                scopes: [Dashboard::class],
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

                    return '';
                },
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => <<<'CSS'
                    <style>
                        .fi-auth-simple-main-ctn {
                            flex-direction: column;
                            gap: 1rem;
                        }

                        .fi-auth-simple-main-ctn .fi-simple-main {
                            margin-top: 0;
                        }

                        .fi-auth-wordmark {
                            color: #ffffff;
                            text-align: center;
                        }

                        @media (min-width: 1024px) {
                            .position-form-columns.fi-grid {
                                align-items: stretch;
                            }

                            .position-form-columns.fi-grid > .fi-grid-col {
                                display: flex;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-columns.fi-grid > .fi-grid-col > .fi-sc-component {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-setup-grid {
                                display: flex;
                                flex-direction: column;
                                gap: 1rem;
                                width: 100%;
                            }

                            .position-form-columns.fi-grid > .fi-grid-col:has(.position-form-journal-section) {
                                align-self: stretch;
                            }

                            .position-form-journal-section.fi-sc-section {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-section .fi-section {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-section .fi-section-content-ctn {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-section .fi-section-content {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                gap: 1rem;
                                min-height: 0;
                            }

                            .position-form-journal-field.fi-fo-field {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-field .fi-fo-field-content-col {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-field .fi-fo-textarea-wrp {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-field .fi-fo-textarea {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-field .fi-fo-textarea > div {
                                flex: 1 !important;
                                height: auto !important;
                                min-height: 6.75rem;
                            }

                            .position-form-journal-field .fi-fo-textarea textarea {
                                height: 100% !important;
                                min-height: 6.75rem;
                                resize: none;
                            }

                            .position-form-journal-section .position-form-chart-upload {
                                flex-shrink: 0;
                            }

                            .position-form-chart-upload .fi-fo-file-upload {
                                width: 100%;
                            }

                            .position-form-chart-upload .filepond--root {
                                max-width: 100%;
                            }

                            .position-form-chart-upload .filepond--image-preview-wrapper,
                            .position-form-chart-upload .filepond--item-panel {
                                border-radius: 0.5rem;
                            }

                            .chart-screenshot-lightbox__trigger {
                                display: block;
                                position: relative;
                                width: 100%;
                                padding: 0;
                                overflow: hidden;
                                cursor: zoom-in;
                                border: 1px solid rgba(255, 255, 255, 0.1);
                                border-radius: 0.5rem;
                                background: rgba(0, 0, 0, 0.25);
                            }

                            .chart-screenshot-lightbox__trigger img {
                                display: block;
                                width: 100%;
                                height: auto;
                            }

                            .chart-screenshot-lightbox__hint {
                                position: absolute;
                                right: 0.5rem;
                                bottom: 0.5rem;
                                padding: 0.2rem 0.5rem;
                                border-radius: 0.25rem;
                                background: rgba(0, 0, 0, 0.7);
                                color: #fff;
                                font-size: 0.7rem;
                                line-height: 1.2;
                            }

                            .chart-screenshot-lightbox__backdrop {
                                position: fixed;
                                inset: 0;
                                z-index: 50;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                padding: 1.5rem;
                                background: rgba(0, 0, 0, 0.85);
                            }

                            .chart-screenshot-lightbox__dialog {
                                position: relative;
                                max-width: min(96vw, 1400px);
                                max-height: 92vh;
                            }

                            .chart-screenshot-lightbox__dialog img {
                                display: block;
                                max-width: 100%;
                                max-height: 92vh;
                                width: auto;
                                height: auto;
                                margin: 0 auto;
                                border-radius: 0.5rem;
                            }

                            .chart-screenshot-lightbox__close {
                                position: absolute;
                                top: -0.25rem;
                                right: -0.25rem;
                                z-index: 1;
                                width: 2rem;
                                height: 2rem;
                                border: none;
                                border-radius: 9999px;
                                background: rgba(0, 0, 0, 0.75);
                                color: #fff;
                                font-size: 1.25rem;
                                line-height: 1;
                                cursor: pointer;
                            }

                            .position-form-chart-upload .filepond--image-preview-wrapper {
                                display: none;
                            }
                        }
                    </style>
                    CSS,
            );
    }
}
