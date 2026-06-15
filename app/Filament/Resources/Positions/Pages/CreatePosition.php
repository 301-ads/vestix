<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use App\Services\MarketDataFetcher;
use App\Support\FilamentNotifier;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreatePosition extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch_market_data')
                ->label('Marktdata ophalen')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->action(function (MarketDataFetcher $marketDataFetcher): void {
                    $ticker = strtoupper(trim((string) ($this->form->getState()['ticker'] ?? '')));

                    if ($ticker === '') {
                        FilamentNotifier::send(
                            title: 'Ticker vereist',
                            body: 'Vul eerst een ticker in voordat je marktdata ophaalt.',
                            status: 'warning',
                        );

                        return;
                    }

                    $data = $marketDataFetcher->fetchForTicker($ticker, withDelays: true);

                    if ($data === null) {
                        FilamentNotifier::send(
                            title: 'Marktdata onvolledig',
                            body: 'API gaf geen complete dataset terug.',
                            status: 'warning',
                        );

                        return;
                    }

                    $sl = Position::computeNewSl($data['latest_sma_20'], $data['latest_atr_14']);

                    $this->form->fill([
                        ...$data,
                        'current_sl' => $sl,
                    ]);

                    FilamentNotifier::send(
                        title: 'Marktdata opgehaald',
                        body: $sl !== null
                            ? "Stop-loss voorgesteld op \${$sl}."
                            : 'Close, SMA en ATR ingevuld.',
                    );
                }),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'open';
        $data['user_id'] = auth()->id();
        $data['visibility'] = 'private';

        if (blank($data['current_sl'] ?? null)) {
            $data['current_sl'] = Position::computeNewSl(
                $data['latest_sma_20'] ?? null,
                $data['latest_atr_14'] ?? null,
            );
        }

        return $data;
    }
}
