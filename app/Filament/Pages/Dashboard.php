<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BuyStopReviewWidget;
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Filament\Widgets\SetupRadarWidget;
use App\Support\BackgroundArtisan;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFreshness;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?int $navigationSort = 1;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['vestix-dashboard'];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            PortfolioExposureWidget::class,
            PortfolioTopFlopWidget::class,
            SetupRadarWidget::class,
            BuyStopReviewWidget::class,
            PositionsRequiringActionWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_api')
                ->label('Forceer API Sync')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->outlined()
                ->extraAttributes(['class' => 'vestix-sync-btn'])
                ->tooltip(MarketDataFreshness::tooltip())
                ->disabled(fn (): bool => MarketDataFreshness::isSyncInProgress())
                ->requiresConfirmation()
                ->modalHeading('Marktdata ophalen')
                ->modalDescription('Weet je zeker dat je marktdata nu wilt ophalen via Polygon? Bij veel posities duurt de sync langer (rate limit: max 5 calls/min op gratis tier). Je krijgt een melding zodra alles klaar is.')
                ->action(function (): void {
                    $userId = auth()->id();

                    BackgroundArtisan::dispatch('vestix:fetch-data', [
                        'user-id' => $userId,
                    ]);

                    FilamentNotifier::send(
                        title: 'API-sync gestart',
                        body: 'De Sniper Engine draait op de achtergrond. Je krijgt een melding zodra de sync klaar is.',
                        recipients: auth()->user(),
                    );
                }),
        ];
    }
}
