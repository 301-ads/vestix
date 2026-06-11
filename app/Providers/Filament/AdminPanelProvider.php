<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Positions\Pages\ListScouts;
use App\Models\Position;
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringLiquidationWidget;
use App\Filament\Widgets\PositionsRequiringUpdateWidget;
use App\Filament\Widgets\SetupRadarWidget;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('SWNG')
            ->brandLogo(asset('images/swng-logo.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/favicon.png'))
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Dark)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                PortfolioExposureWidget::class,
                PositionsRequiringUpdateWidget::class,
                PortfolioTopFlopWidget::class,
                PositionsRequiringLiquidationWidget::class,
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
            ->navigationItems([
                NavigationItem::make('Setup Radar')
                    ->icon(Heroicon::OutlinedViewfinderCircle)
                    ->sort(2)
                    ->badge(fn (): string => (string) Position::query()->scout()->count(), color: 'info')
                    ->badgeTooltip('Scouts in watchlist')
                    ->url(fn (): string => ListScouts::getUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs([
                        'filament.admin.resources.positions.scouts',
                        'filament.admin.resources.positions.create-scout',
                        'filament.admin.resources.positions.edit-scout',
                    ])),
            ])
            ->renderHook(
                PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
                fn (): string => view('filament.dashboard.market-data-status')->render(),
                scopes: [Dashboard::class],
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => <<<'CSS'
                    <style>
                        @media (min-width: 1024px) {
                            .position-form-columns.fi-grid {
                                align-items: stretch;
                            }

                            .position-form-columns .fi-grid > .fi-grid-col {
                                display: flex;
                                flex-direction: column;
                            }

                            .position-form-columns .fi-grid > .fi-grid-col > div {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                            }

                            .position-form-columns .fi-grid > .fi-grid-col .fi-sc-component {
                                flex: 1;
                                height: auto;
                            }

                            .position-form-setup-grid {
                                display: flex;
                                flex-direction: column;
                                gap: 1rem;
                                align-self: start;
                                width: 100%;
                            }

                            .position-form-journal-section {
                                align-self: stretch;
                            }

                            .position-form-journal-section .fi-section {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                height: 100%;
                            }

                            .position-form-journal-section .fi-section-content-ctn {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                            }

                            .position-form-journal-section .fi-section-content {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                            }

                            .position-form-journal-section .fi-section-content > .fi-sc {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                            }

                            .position-form-journal-field {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                                min-height: 0;
                            }

                            .position-form-journal-field .fi-fo-textarea {
                                display: flex;
                                flex: 1;
                                flex-direction: column;
                            }

                            .position-form-journal-field .fi-fo-textarea > div {
                                flex: 1 !important;
                                height: 100% !important;
                                min-height: 0;
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

                        .fi-wi-stats-overview-stat .fi-wi-stats-overview-stat-value.fi-color {
                            color: var(--text);
                        }

                        .dark .fi-wi-stats-overview-stat .fi-wi-stats-overview-stat-value.fi-color {
                            color: var(--dark-text);
                        }

                        .fi-header-heading .position-edit-heading {
                            display: inline-flex;
                            align-items: center;
                            gap: 1.25rem;
                        }

                        .scout-activate-a-plus.fi-btn {
                            box-shadow: 0 0 0 1px rgb(52 211 153 / 0.5), 0 0 20px rgb(52 211 153 / 0.35);
                        }

                        .scout-activate-a-plus.fi-btn:hover {
                            box-shadow: 0 0 0 1px rgb(52 211 153 / 0.7), 0 0 28px rgb(52 211 153 / 0.45);
                        }

                        .scout-scorecard-hud {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 1.5rem;
                            padding: 1.5rem;
                            margin-bottom: 0.5rem;
                            border-radius: 1rem;
                            border: 1px solid var(--gray-200);
                            background: var(--gray-50);
                        }

                        .dark .scout-scorecard-hud {
                            border-color: rgb(255 255 255 / 0.1);
                            background: rgb(255 255 255 / 0.05);
                        }

                        .scout-scorecard-hud--hard-fail {
                            border-color: var(--danger-300);
                        }

                        .dark .scout-scorecard-hud--hard-fail {
                            border-color: var(--danger-500);
                        }

                        .scout-scorecard-hud-label {
                            display: block;
                            margin-bottom: 0.25rem;
                            font-size: 0.75rem;
                            font-weight: 800;
                            letter-spacing: 0.1em;
                            text-transform: uppercase;
                            color: var(--gray-500);
                        }

                        .dark .scout-scorecard-hud-label {
                            color: var(--gray-400);
                        }

                        .scout-scorecard-hud-score-row {
                            display: flex;
                            align-items: baseline;
                            gap: 0.125rem;
                        }

                        .scout-scorecard-hud-score {
                            font-size: 3.75rem;
                            font-weight: 900;
                            line-height: 1;
                        }

                        .scout-scorecard-hud-score.fi-color {
                            color: var(--text);
                        }

                        .dark .scout-scorecard-hud-score.fi-color {
                            color: var(--dark-text);
                        }

                        .scout-scorecard-hud-max {
                            font-size: 1.875rem;
                            font-weight: 700;
                            color: var(--gray-600);
                        }

                        .dark .scout-scorecard-hud-max {
                            color: var(--gray-400);
                        }

                        .scout-scorecard-hud-grade {
                            flex-shrink: 0;
                            text-align: right;
                        }

                        .scout-scorecard-criterion {
                            height: 100%;
                            padding: 1rem;
                            border-radius: 0.75rem;
                            border: 1px solid var(--gray-200);
                            background: var(--gray-50);
                        }

                        .dark .scout-scorecard-criterion {
                            border-color: rgb(255 255 255 / 0.1);
                            background: rgb(255 255 255 / 0.05);
                        }

                        .scout-scorecard-criterion--pass {
                            border-color: var(--success-200);
                        }

                        .dark .scout-scorecard-criterion--pass {
                            border-color: var(--success-500);
                        }

                        .scout-scorecard-criterion--warn {
                            border-color: var(--warning-200);
                        }

                        .dark .scout-scorecard-criterion--warn {
                            border-color: var(--warning-500);
                        }

                        /* Table text columns use success-600 in dark mode; stats use success-400 */
                        .dark .fi-ta-text-item.fi-color.fi-color-success {
                            --dark-text: var(--success-400);
                        }

                        .dark .fi-ta-text-item.fi-color.fi-color-danger {
                            --dark-text: var(--danger-400);
                        }

                        /* Dashboard: table widgets in the same grid row share height */
                        .swng-dashboard .fi-sc.fi-grid {
                            align-items: stretch;
                        }

                        .swng-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table {
                            display: flex;
                            flex-direction: column;
                            align-self: stretch;
                            height: 100%;
                            min-height: 0;
                        }

                        .swng-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table > .fi-ta {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            min-height: 0;
                        }

                        .swng-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-ctn {
                            flex: 1;
                            flex-direction: column;
                            width: 100%;
                            min-height: 0;
                        }

                        .swng-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-main {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            min-height: 0;
                        }

                        .swng-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-content-ctn {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            min-height: 0;
                        }

                        .swng-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-empty-state {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            justify-content: center;
                            min-height: 0;
                        }
                    </style>
                    CSS,
            );
    }
}
