<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Enums\PositionVisibility;
use App\Events\SquadRadarTargetPosted;
use App\Filament\Concerns\PollsTickerMarketData;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Models\Squad;
use App\Services\SquadContext;
use App\Support\MarketDataFetchDispatcher;
use App\Support\MarketDataFreshness;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateScout extends CreateRecord
{
    use PollsTickerMarketData;

    protected static string $resource = ScoutResource::class;

    protected static ?string $title = 'Scout toevoegen';

    protected static ?string $breadcrumb = 'Scout toevoegen';

    public function form(Schema $schema): Schema
    {
        return PositionForm::configure($schema, scoutMode: true);
    }

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
                ) ? 'Bezig…' : 'Data ophalen')
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
        $state = $this->form->getRawState();

        $buyStop = Position::computeBuyStop(
            $state['signal_high'] ?? null,
            $data['latest_atr_14'],
        );

        if ($buyStop !== null) {
            $fill['advised_entry'] = $buyStop;
            $fill['entry_price'] = $buyStop;
        }

        return $fill;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'scout';
        $data['user_id'] = auth()->id();

        $visibility = PositionVisibility::tryFrom((string) ($data['visibility'] ?? ''))
            ?? PositionVisibility::Private;

        $user = auth()->user();
        $squadId = isset($data['squad_id']) ? (int) $data['squad_id'] : null;
        $squad = $user && $squadId
            ? $user->squads()->whereKey($squadId)->first()
            : null;

        if (
            $visibility === PositionVisibility::Squad
            && $user !== null
            && $squad instanceof Squad
            && app(SquadContext::class)->userCanInSquad($user, $squad, 'scout.share')
        ) {
            $data['visibility'] = PositionVisibility::Squad->value;
            $data['squad_id'] = $squad->id;
        } else {
            $data['visibility'] = PositionVisibility::Private->value;
            $data['squad_id'] = null;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record->visibility === PositionVisibility::Squad) {
            SquadRadarTargetPosted::dispatch($record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
