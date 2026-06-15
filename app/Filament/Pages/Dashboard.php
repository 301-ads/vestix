<?php

namespace App\Filament\Pages;

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_api')
                ->label('Forceer API Sync')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->tooltip(MarketDataFreshness::tooltip())
                ->disabled(fn (): bool => MarketDataFreshness::isSyncInProgress())
                ->requiresConfirmation()
                ->modalHeading('Marktdata ophalen')
                ->modalDescription('Weet je zeker dat je de Alpha Vantage API nu wilt aanroepen? Let op je API-limieten (max 5 per minuut). Dit kan enkele minuten duren.')
                ->action(function (): void {
                    $userId = auth()->id();

                    BackgroundArtisan::dispatch('vestix:fetch-data', [
                        'user-id' => $userId,
                    ]);

                    FilamentNotifier::send(
                        title: 'API-sync gestart',
                        body: 'De Sluipschutter Engine draait op de achtergrond. Je krijgt een melding zodra de sync klaar is.',
                        recipients: auth()->user(),
                    );
                }),
        ];
    }
}
