<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BankrollUpdateWidget;
use App\Filament\Widgets\FirstRunChecklistWidget;
use App\Filament\Widgets\OrderPlanTodayWidget;
use App\Filament\Widgets\PortfolioExposureWidget;
use App\Filament\Widgets\PortfolioTopFlopWidget;
use App\Filament\Widgets\PositionsRequiringActionWidget;
use App\Filament\Widgets\SetupRadarWidget;
use App\Support\BackgroundArtisan;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFreshness;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\HtmlString;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Tactisch';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Dashboard';

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['vestix-dashboard', 'vestix-dashboard--today'];
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    /**
     * Today Mode: action queue first, then portfolio context, then Order Plan under Top/Flop + Radar.
     *
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            FirstRunChecklistWidget::class,
            PositionsRequiringActionWidget::class,
            BankrollUpdateWidget::class,
            PortfolioExposureWidget::class,
            PortfolioTopFlopWidget::class,
            SetupRadarWidget::class,
            OrderPlanTodayWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_api')
                ->label(new HtmlString(
                    '<span class="vestix-sync-btn__label vestix-sync-btn__label--full">Forceer API Sync</span>'
                    .'<span class="vestix-sync-btn__label vestix-sync-btn__label--short">Sync</span>'
                ))
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
