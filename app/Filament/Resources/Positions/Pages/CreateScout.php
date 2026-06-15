<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Enums\PositionVisibility;
use App\Events\SquadRadarTargetPosted;
use App\Filament\Resources\Positions\Schemas\PositionForm;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Models\Squad;
use App\Services\MarketDataFetcher;
use App\Services\SquadContext;
use App\Support\FilamentNotifier;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;

class CreateScout extends CreateRecord
{
    protected static string $resource = ScoutResource::class;

    protected static ?string $title = 'Scout toevoegen';

    protected static ?string $breadcrumb = 'Scout toevoegen';

    public function form(Schema $schema): Schema
    {
        return PositionForm::configure($schema, scoutMode: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch_market_data')
                ->label('Data ophalen')
                ->tooltip('Haal actuele koers (Polygon/Alpha Vantage), SMA20, SMA50, ATR14 en RSI op')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->action(function (MarketDataFetcher $marketDataFetcher): void {
                    $state = $this->form->getRawState();
                    $ticker = strtoupper(trim((string) ($state['ticker'] ?? '')));

                    if ($ticker === '') {
                        FilamentNotifier::send(
                            title: 'Ticker ontbreekt',
                            body: 'Kies eerst een ticker voordat je marktdata ophaalt.',
                            status: 'warning',
                        );

                        return;
                    }

                    $lock = Cache::lock(MarketDataFetcher::syncLockKey(), 120);

                    if (! $lock->get()) {
                        FilamentNotifier::send(
                            title: 'API-sync bezig',
                            body: 'Er loopt al een marktdata-sync. Wacht even en probeer opnieuw.',
                            status: 'warning',
                        );

                        return;
                    }

                    try {
                        $data = $marketDataFetcher->fetchForTicker($ticker, withDelays: true);

                        if ($data === null) {
                            FilamentNotifier::send(
                                title: 'Marktdata onvolledig',
                                body: 'Alpha Vantage gaf geen complete dataset terug (vaak rate limit: max 5 calls/min op gratis tier). Wacht ~1 minuut of vul handmatig in.',
                                status: 'warning',
                            );

                            return;
                        }

                        $fill = array_merge($state, $data);

                        $buyStop = Position::computeBuyStop(
                            $state['signal_high'] ?? null,
                            $data['latest_atr_14'],
                        );

                        if ($buyStop !== null) {
                            $fill['advised_entry'] = $buyStop;
                            $fill['entry_price'] = $buyStop;
                        }

                        $this->form->fill($fill);

                        $close = '$'.number_format((float) $data['latest_close_price'], 2);

                        FilamentNotifier::send(
                            title: 'Marktdata bijgewerkt',
                            body: "{$ticker}: koers {$close}, SMA20, SMA50, ATR en RSI ingevuld.",
                        );
                    } finally {
                        $lock->release();
                    }
                }),
        ];
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
