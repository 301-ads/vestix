<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use App\Services\MarketDataFetcher;
use App\Support\ChartScreenshotUpload;
use App\Support\FilamentNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class PositionRecordActions
{
    public static function fetchMarketData(): Action
    {
        return Action::make('fetch_market_data')
            ->label('Data ophalen')
            ->tooltip('Haal actuele koers (Polygon/Alpha Vantage), SMA20, SMA50, ATR14 en RSI op')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (Position $record): bool => in_array($record->status, ['open', 'scout'], true))
            ->action(function (Position $record, MarketDataFetcher $marketDataFetcher, $livewire): void {
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
                    if ($marketDataFetcher->syncPosition($record, withDelays: true)) {
                        $record->refresh();

                        if (is_object($livewire) && method_exists($livewire, 'getRecord')) {
                            $livewire->getRecord()->refresh();
                        }

                        if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
                            $livewire->refreshFormData([
                                'latest_close_price',
                                'latest_sma_20',
                                'sma_20_five_days_ago',
                                'latest_sma_50',
                                'latest_atr_14',
                                'scout_rsi',
                            ]);
                        }

                        $close = $record->latest_close_price !== null
                            ? '$'.number_format((float) $record->latest_close_price, 2)
                            : 'onbekend';

                        FilamentNotifier::send(
                            title: 'Marktdata bijgewerkt',
                            body: "{$record->ticker}: koers {$close}, SMA20, SMA50, ATR en RSI opgehaald.",
                        );

                        return;
                    }
                } finally {
                    $lock->release();
                }

                FilamentNotifier::send(
                    title: 'Marktdata onvolledig',
                    body: 'Alpha Vantage gaf geen complete dataset terug (vaak rate limit: max 5 calls/min op gratis tier). Wacht ~1 minuut of vul handmatig in.',
                    status: 'warning',
                );
            });
    }

    public static function activateScout(): Action
    {
        return Action::make('activate_scout')
            ->label('Activeren')
            ->tooltip('Zet scout om naar open positie met berekende stop-loss')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'scout')
            ->modalHeading('Scout activeren als positie')
            ->modalDescription('Vul je werkelijke fill en aantal in. De broker stop-loss wordt automatisch gezet op de berekende SL.')
            ->schema([
                TextInput::make('entry_price')
                    ->label('Entry prijs')
                    ->numeric()
                    ->required()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->default(fn (Position $record): ?float => $record->entry_price !== null
                        ? (float) $record->entry_price
                        : null),
                TextInput::make('quantity')
                    ->label('Aantal')
                    ->numeric()
                    ->required()
                    ->inputMode('decimal')
                    ->step('any')
                    ->minValue(0.000001)
                    ->default(fn (Position $record): ?float => $record->quantity !== null
                        ? (float) $record->quantity
                        : null),
                Placeholder::make('sl_preview')
                    ->label('Broker stop-loss')
                    ->content(fn (Position $record): HtmlString => new HtmlString(
                        '<span class="text-lg font-semibold">'.self::formatPreviewSl($record).'</span>'
                    )),
            ])
            ->action(function (Position $record, array $data): void {
                $record->activateAsPosition(
                    (float) $data['entry_price'],
                    (float) $data['quantity'],
                );

                FilamentNotifier::send(
                    title: 'Scout geactiveerd',
                    body: "{$record->ticker} is nu een open positie.",
                );
            })
            ->successRedirectUrl(fn (Position $record): string => PositionResource::getUrl('edit', ['record' => $record]));
    }

    public static function markAsUpdated(): Action
    {
        return Action::make('mark_as_updated')
            ->label('Update')
            ->tooltip('Stop-Loss bijwerken naar berekende SL')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'open' && $record->action_command === 'UPDATE')
            ->requiresConfirmation()
            ->modalHeading('Stop-Loss bijwerken')
            ->modalDescription('Huidige SL vervangen door de berekende nieuwe SL?')
            ->action(function (Position $record): void {
                $record->update(['current_sl' => $record->new_sl]);

                FilamentNotifier::send(title: 'Stop-Loss geüpdatet!');
            });
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(fn (Position $record): string => $record->action_command === 'STOPPED OUT'
                ? 'Schild Geraakt (Sluit)'
                : 'Archiveer')
            ->tooltip('Sluit de positie en verplaats naar archief')
            ->icon('heroicon-o-archive-box')
            ->color(fn (Position $record): string => $record->action_command === 'STOPPED OUT'
                ? 'warning'
                : 'gray')
            ->visible(fn (Position $record): bool => $record->status === 'open')
            ->modalHeading(fn (Position $record): string => $record->action_command === 'STOPPED OUT'
                ? 'Positie sluiten na stop-loss'
                : 'Positie archiveren')
            ->modalDescription('Voor welke prijs is de trade definitief gesloten bij je broker?')
            ->schema([
                TextInput::make('exit_price')
                    ->label('Werkelijke verkoopprijs')
                    ->numeric()
                    ->required()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->default(fn (Position $record): ?float => self::defaultExitPrice($record)),
                ChartScreenshotUpload::make('exit_chart_screenshot_path')
                    ->label('TradingView — exit')
                    ->imagePreviewHeight('160')
                    ->helperText('Optioneel: upload je exit-chart voor je trade journal. '.ChartScreenshotUpload::maxSizeLabel()),
            ])
            ->action(function (Position $record, array $data): void {
                $record->archiveWithExitPrice(
                    (float) $data['exit_price'],
                    $data['exit_chart_screenshot_path'] ?? null,
                );

                FilamentNotifier::send(title: 'Positie gearchiveerd');
            });
    }

    private static function defaultExitPrice(Position $record): ?float
    {
        if ($record->action_command === 'STOPPED OUT') {
            return (float) $record->current_sl;
        }

        if ($record->latest_close_price !== null) {
            return (float) $record->latest_close_price;
        }

        return null;
    }

    private static function formatPreviewSl(Position $record): string
    {
        $sl = Position::computeNewSl($record->latest_sma_20, $record->latest_atr_14);

        if ($sl === null) {
            return '— (haal eerst marktdata op)';
        }

        return '$'.number_format($sl, 2);
    }
}
