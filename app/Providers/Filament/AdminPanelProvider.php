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
            ->favicon(asset('images/favicon.svg'))
            ->colors([
                'primary' => Color::generatePalette('#00D492'),
            ])
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

                        .fi-header .fi-header-actions .fi-btn.vestix-sync-btn {
                            gap: 0.5rem;
                            padding: 0.5rem 1rem;
                            border-radius: 0.75rem;
                            border: 1px solid rgb(0 212 146 / 0.3);
                            background-color: rgb(0 212 146 / 0.1);
                            color: #00d492;
                            font-size: 0.875rem;
                            font-weight: 600;
                            --tw-ring-width: 0;
                            --tw-ring-shadow: 0 0 #0000;
                            --tw-shadow: 0 0 #0000;
                            box-shadow: 0 0 15px rgb(0 212 146 / 0.1);
                            transition: all 150ms;
                        }

                        .fi-header .fi-header-actions .fi-btn.vestix-sync-btn > .fi-icon {
                            width: 1rem;
                            height: 1rem;
                            color: #00d492;
                        }

                        .fi-header .fi-header-actions .fi-btn.vestix-sync-btn:hover,
                        .fi-header .fi-header-actions .fi-btn.vestix-sync-btn:focus-visible {
                            border-color: rgb(0 212 146 / 0.5);
                            background-color: rgb(0 212 146 / 0.2);
                            color: #00d492;
                            box-shadow: 0 0 15px rgb(0 212 146 / 0.1);
                        }

                        .fi-header .fi-header-actions .fi-btn.vestix-sync-btn:hover > .fi-icon,
                        .fi-header .fi-header-actions .fi-btn.vestix-sync-btn:focus-visible > .fi-icon {
                            color: #00d492;
                        }

                        .fi-btn.vestix-btn-primary {
                            gap: 0.5rem;
                            padding: 0.5rem 1rem;
                            border: none;
                            background-color: #00d492;
                            color: #09090b;
                            font-size: 0.875rem;
                            font-weight: 700;
                            --tw-ring-width: 0;
                            --tw-ring-shadow: 0 0 #0000;
                            --tw-shadow: 0 0 #0000;
                            box-shadow: 0 0 15px rgb(0 212 146 / 0.2);
                        }

                        .fi-btn.vestix-btn-primary > .fi-icon {
                            color: #09090b;
                        }

                        .fi-btn.vestix-btn-primary:hover,
                        .fi-btn.vestix-btn-primary:focus-visible {
                            background-color: #00e6a5;
                            color: #09090b;
                            box-shadow: 0 0 20px rgb(0 212 146 / 0.3);
                        }

                        .fi-btn.vestix-btn-primary:hover > .fi-icon,
                        .fi-btn.vestix-btn-primary:focus-visible > .fi-icon {
                            color: #09090b;
                        }

                        .vestix-dashboard .fi-ta-header-cell:first-of-type,
                        .vestix-positions-list .fi-ta-header-cell:first-of-type,
                        .vestix-radar-list .fi-ta-header-cell:first-of-type {
                            padding-inline-start: 1rem;
                        }

                        .vestix-dashboard .fi-ta-header-cell:not(:first-of-type),
                        .vestix-positions-list .fi-ta-header-cell:not(:first-of-type),
                        .vestix-radar-list .fi-ta-header-cell:not(:first-of-type) {
                            padding-inline-start: 0.75rem;
                        }

                        @media (min-width: 640px) {
                            .vestix-dashboard .fi-ta-header-cell:first-of-type,
                            .vestix-positions-list .fi-ta-header-cell:first-of-type,
                            .vestix-radar-list .fi-ta-header-cell:first-of-type {
                                padding-inline-start: 1rem;
                            }

                            .vestix-dashboard .fi-ta-header-cell:last-of-type,
                            .vestix-positions-list .fi-ta-header-cell:last-of-type,
                            .vestix-radar-list .fi-ta-header-cell:last-of-type {
                                padding-inline-end: 1rem;
                            }
                        }

                        .vestix-dashboard .fi-ta-header-cell,
                        .vestix-positions-list .fi-ta-header-cell,
                        .vestix-radar-list .fi-ta-header-cell,
                        .vestix-dashboard .fi-ta-header-group-cell,
                        .vestix-positions-list .fi-ta-header-group-cell,
                        .vestix-radar-list .fi-ta-header-group-cell {
                            padding-block: 0.625rem;
                            padding-inline-end: 0.75rem;
                            font-size: 0.6875rem;
                            font-weight: 600;
                            letter-spacing: 0.06em;
                            text-transform: uppercase;
                            color: rgb(113 113 122);
                        }

                        .vestix-dashboard .fi-ta-header-cell .fi-ta-header-cell-sort-btn,
                        .vestix-positions-list .fi-ta-header-cell .fi-ta-header-cell-sort-btn,
                        .vestix-radar-list .fi-ta-header-cell .fi-ta-header-cell-sort-btn {
                            font-size: inherit;
                            font-weight: inherit;
                            letter-spacing: inherit;
                            text-transform: uppercase;
                            color: inherit;
                        }

                        .vestix-positions-list thead .fi-ta-selection-cell,
                        .vestix-radar-list thead .fi-ta-selection-cell {
                            padding-block: 0.5rem !important;
                        }

                        .vestix-dashboard .fi-ta-cell:first-of-type,
                        .vestix-positions-list .fi-ta-cell:first-of-type,
                        .vestix-radar-list .fi-ta-cell:first-of-type {
                            padding-inline-start: 1rem !important;
                        }

                        .vestix-dashboard .fi-ta-text:not(.fi-inline),
                        .vestix-positions-list .fi-ta-text:not(.fi-inline),
                        .vestix-radar-list .fi-ta-text:not(.fi-inline) {
                            padding-block: 0.5rem;
                            padding-inline-end: 0.75rem;
                        }

                        .vestix-dashboard .fi-ta-cell:first-of-type .fi-ta-text:not(.fi-inline),
                        .vestix-positions-list .fi-ta-cell:first-of-type .fi-ta-text:not(.fi-inline),
                        .vestix-radar-list .fi-ta-cell:first-of-type .fi-ta-text:not(.fi-inline) {
                            padding-inline-start: 0;
                            padding-inline-end: 0.75rem;
                        }

                        .vestix-dashboard .fi-ta-cell:not(:first-of-type) .fi-ta-text:not(.fi-inline),
                        .vestix-positions-list .fi-ta-cell:not(:first-of-type) .fi-ta-text:not(.fi-inline),
                        .vestix-radar-list .fi-ta-cell:not(:first-of-type) .fi-ta-text:not(.fi-inline) {
                            padding-inline-start: 0.75rem;
                        }

                        .vestix-dashboard .fi-ta-cell:has(.fi-ta-actions),
                        .vestix-positions-list .fi-ta-cell:has(.fi-ta-actions),
                        .vestix-radar-list .fi-ta-cell:has(.fi-ta-actions) {
                            padding-block: 0.5rem;
                            padding-inline: 0.75rem;
                        }

                        .vestix-positions-list .fi-ta-text-input:not(.fi-inline),
                        .vestix-radar-list .fi-ta-text-input:not(.fi-inline) {
                            padding-block: 0.5rem;
                            padding-inline: 0.75rem;
                        }

                        .vestix-positions-list .fi-ta-text-input input:disabled,
                        .vestix-radar-list .fi-ta-text-input input:disabled {
                            opacity: 1;
                            cursor: default;
                        }

                        :not(.dark) .vestix-positions-list .fi-ta-text-input input:disabled,
                        :not(.dark) .vestix-radar-list .fi-ta-text-input input:disabled {
                            color: rgb(24 24 27);
                        }

                        .dark .vestix-positions-list .fi-ta-text-input input:disabled,
                        .dark .vestix-radar-list .fi-ta-text-input input:disabled {
                            color: rgb(255 255 255);
                        }

                        .vestix-positions-list .fi-ta-actions .fi-icon-btn,
                        .vestix-radar-list .fi-ta-actions .fi-icon-btn {
                            border-radius: 0.5rem;
                            transition: background-color 150ms, color 150ms;
                        }

                        .vestix-positions-list .fi-ta-actions .fi-icon-btn > .fi-icon,
                        .vestix-radar-list .fi-ta-actions .fi-icon-btn > .fi-icon {
                            color: rgb(113 113 122);
                            transition: color 150ms;
                        }

                        :not(.dark) .vestix-positions-list .fi-ta-actions .fi-icon-btn:hover,
                        :not(.dark) .vestix-positions-list .fi-ta-actions .fi-icon-btn:focus-visible,
                        :not(.dark) .vestix-radar-list .fi-ta-actions .fi-icon-btn:hover,
                        :not(.dark) .vestix-radar-list .fi-ta-actions .fi-icon-btn:focus-visible {
                            background-color: rgb(228 228 231 / 0.8);
                        }

                        :not(.dark) .vestix-positions-list .fi-ta-actions .fi-icon-btn:hover > .fi-icon,
                        :not(.dark) .vestix-positions-list .fi-ta-actions .fi-icon-btn:focus-visible > .fi-icon,
                        :not(.dark) .vestix-radar-list .fi-ta-actions .fi-icon-btn:hover > .fi-icon,
                        :not(.dark) .vestix-radar-list .fi-ta-actions .fi-icon-btn:focus-visible > .fi-icon {
                            color: rgb(0 0 0);
                        }

                        .dark .vestix-positions-list .fi-ta-actions .fi-icon-btn:hover,
                        .dark .vestix-positions-list .fi-ta-actions .fi-icon-btn:focus-visible,
                        .dark .vestix-radar-list .fi-ta-actions .fi-icon-btn:hover,
                        .dark .vestix-radar-list .fi-ta-actions .fi-icon-btn:focus-visible {
                            background-color: rgb(63 63 70 / 0.5);
                        }

                        .dark .vestix-positions-list .fi-ta-actions .fi-icon-btn:hover > .fi-icon,
                        .dark .vestix-positions-list .fi-ta-actions .fi-icon-btn:focus-visible > .fi-icon,
                        .dark .vestix-radar-list .fi-ta-actions .fi-icon-btn:hover > .fi-icon,
                        .dark .vestix-radar-list .fi-ta-actions .fi-icon-btn:focus-visible > .fi-icon {
                            color: rgb(255 255 255);
                        }

                        .vestix-dashboard .fi-ta-text .ticker-with-icon,
                        .vestix-positions-list .fi-ta-text .ticker-with-icon,
                        .vestix-radar-list .fi-ta-text .ticker-with-icon {
                            margin-inline-start: 0;
                        }

                        .vestix-dashboard .fi-ta-text .ticker-with-icon__logo,
                        .vestix-positions-list .fi-ta-text .ticker-with-icon__logo,
                        .vestix-radar-list .fi-ta-text .ticker-with-icon__logo {
                            width: 1.25rem;
                            height: 1.25rem;
                        }

                        .vestix-dashboard .fi-ta-text .ticker-with-icon__label,
                        .vestix-positions-list .fi-ta-text .ticker-with-icon__label,
                        .vestix-radar-list .fi-ta-text .ticker-with-icon__label {
                            font-weight: 700;
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

                        .ticker-with-icon {
                            display: inline-flex;
                            align-items: center;
                            gap: 0.5em;
                            max-width: 100%;
                            font-size: inherit;
                            line-height: 1;
                            white-space: nowrap;
                        }

                        .ticker-with-icon__logo {
                            display: inline-flex;
                            flex-shrink: 0;
                            width: 1em;
                            height: 1em;
                            overflow: hidden;
                            border-radius: 50%;
                        }

                        .ticker-with-icon__image {
                            display: block;
                            width: 100%;
                            height: 100%;
                            object-fit: cover;
                        }

                        .ticker-with-icon__label {
                            line-height: 1;
                        }

                        .fi-ta-text .ticker-with-icon {
                            vertical-align: middle;
                        }

                        .fi-global-search-result-heading .ticker-with-icon {
                            display: inline-flex;
                            align-items: center;
                            gap: 0.5em;
                            font-size: inherit;
                            line-height: 1.2;
                        }

                        .fi-global-search-result-heading .ticker-with-icon__logo {
                            width: 1.125em;
                            height: 1.125em;
                        }

                        .scout-activate-a-plus.fi-btn {
                            box-shadow: 0 0 0 1px rgb(52 211 153 / 0.5), 0 0 20px rgb(52 211 153 / 0.35);
                        }

                        .scout-activate-a-plus.fi-btn:hover {
                            box-shadow: 0 0 0 1px rgb(52 211 153 / 0.7), 0 0 28px rgb(52 211 153 / 0.45);
                        }

                        .scout-visibility-section .fi-section-content-ctn:empty,
                        .scout-visibility-section .fi-section-content:empty {
                            display: none;
                        }

                        .scout-visibility-section .fi-section-header {
                            align-items: center;
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
                        .vestix-dashboard .fi-sc.fi-grid {
                            align-items: stretch;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table {
                            display: flex;
                            flex-direction: column;
                            align-self: stretch;
                            height: 100%;
                            min-height: 0;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table > .fi-ta {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            min-height: 0;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-ctn {
                            flex: 1;
                            flex-direction: column;
                            width: 100%;
                            min-height: 0;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-ctn:has(.fi-ta-empty-state) {
                            display: flex;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-main {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            min-height: 0;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-content-ctn {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            min-height: 0;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-ctn:has(.fi-ta-empty-state) .fi-ta-content-ctn {
                            display: none;
                        }

                        .vestix-dashboard .fi-sc.fi-grid > .fi-wi-widget.fi-wi-table .fi-ta-empty-state {
                            display: flex;
                            flex: 1;
                            flex-direction: column;
                            justify-content: center;
                            align-items: center;
                            border-top: none;
                            padding-block: 0;
                            min-height: 0;
                        }

                        /* Full-width leaderboard: compact empty state, no stretch */
                        .vestix-dashboard .vestix-leaderboard-widget.fi-wi-widget {
                            height: auto;
                            align-self: start;
                        }

                        .vestix-dashboard .vestix-leaderboard-widget .fi-ta {
                            flex: none;
                        }

                        .vestix-dashboard .vestix-leaderboard-widget .fi-ta-ctn:has(.fi-ta-empty-state) {
                            display: block;
                        }

                        .vestix-dashboard .vestix-leaderboard-widget .fi-ta-ctn:has(.fi-ta-empty-state) .fi-ta-content-ctn {
                            display: none !important;
                        }

                        .vestix-dashboard .vestix-leaderboard-widget .fi-ta-empty-state {
                            flex: none;
                            padding: 2.5rem 1.5rem;
                            min-height: auto;
                            border-top: none;
                        }
                    </style>
                    CSS,
            );
    }
}
