<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Enums\TradeDirection;
use App\Filament\Concerns\PollsTickerMarketData;
use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use App\Support\MarketDataFetchDispatcher;
use App\Support\MarketDataFreshness;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreatePosition extends CreateRecord
{
    use PollsTickerMarketData;

    protected static string $resource = PositionResource::class;

    public function mount(): void
    {
        parent::mount();

        $ticker = strtoupper(trim((string) ($this->form->getRawState()['ticker'] ?? '')));
        $userId = auth()->id();

        if ($ticker !== '' && $userId !== null && MarketDataFreshness::isTickerSyncInProgress($userId, $ticker)) {
            $this->startPollingTickerMarketData($ticker);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch_market_data')
                ->label(fn (): string => $this->pollingTicker !== null && MarketDataFreshness::isTickerSyncInProgress(
                    auth()->id() ?? 0,
                    $this->pollingTicker,
                ) ? 'Bezig…' : 'Marktdata ophalen')
                ->tooltip('Haal actuele koers (Polygon), SMA20, SMA50, ATR14 en RSI op')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->disabled(function (): bool {
                    $ticker = strtoupper(trim((string) ($this->form->getRawState()['ticker'] ?? '')));
                    $userId = auth()->id();

                    if ($ticker === '' || $userId === null) {
                        return MarketDataFreshness::isSyncInProgress();
                    }

                    return MarketDataFreshness::isTickerSyncInProgress($userId, $ticker)
                        || MarketDataFreshness::isSyncInProgress();
                })
                ->action(function (): void {
                    $ticker = strtoupper(trim((string) ($this->form->getRawState()['ticker'] ?? '')));

                    if (! MarketDataFetchDispatcher::dispatchTickerFetch($ticker)) {
                        return;
                    }

                    $this->startPollingTickerMarketData($ticker);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $fill
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateTickerMarketDataFill(array $fill, array $data): array
    {
        $sl = Position::computeNewSl(
            $data['latest_sma_20'],
            $data['latest_atr_14'],
            $data['direction'] ?? TradeDirection::Long,
        );

        if ($sl !== null) {
            $fill['current_sl'] = $sl;
        }

        return $fill;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'open';
        $data['user_id'] = auth()->id();
        $data['visibility'] = 'private';
        $data['direction'] = TradeDirection::Long->value;

        if (blank($data['current_sl'] ?? null)) {
            $data['current_sl'] = Position::computeNewSl(
                $data['latest_sma_20'] ?? null,
                $data['latest_atr_14'] ?? null,
                TradeDirection::Long,
            );
        }

        return $data;
    }
}
