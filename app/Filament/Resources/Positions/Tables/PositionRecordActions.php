<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Enums\BrokerOrderStatus;
use App\Enums\ScoutPipelineStatus;
use App\Events\PositionLiquidated;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Services\SquadContext;
use App\Support\ChartScreenshotUpload;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFetchDispatcher;
use App\Support\MarketDataFreshness;
use App\Support\PositionSizing;
use App\Support\ScoutSetupScorecard;
use App\Support\ShareCardDataFactory;
use App\Support\StopLossProtocol;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Illuminate\Support\HtmlString;

class PositionRecordActions
{
    public static function fetchMarketData(bool $syncButtonStyle = false): Action
    {
        $action = Action::make('fetch_market_data')
            ->label(fn (Position $record): string => MarketDataFreshness::isPositionSyncInProgress($record->id)
                ? 'Bezig…'
                : 'Data ophalen')
            ->tooltip('Haal actuele koers (Polygon), SMA20, SMA50, ATR14 en RSI op')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->disabled(fn (Position $record): bool => MarketDataFreshness::isPositionSyncInProgress($record->id)
                || MarketDataFreshness::isSyncInProgress())
            ->visible(fn (Position $record): bool => in_array($record->status, ['open', 'scout'], true))
            ->action(function (Position $record, $livewire): void {
                if (! MarketDataFetchDispatcher::dispatchPositionFetch($record)) {
                    return;
                }

                if (is_object($livewire) && method_exists($livewire, 'startPollingPositionMarketData')) {
                    $livewire->startPollingPositionMarketData();
                }
            });

        if ($syncButtonStyle) {
            $action
                ->color('primary')
                ->outlined()
                ->extraAttributes(['class' => 'vestix-sync-btn']);
        }

        return $action;
    }

    public static function activateScout(bool $iconButton = true): Action
    {
        $action = Action::make('activate_scout')
            ->label('Activeren')
            ->tooltip('Zet scout om naar open positie met berekende stop-loss')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->extraAttributes(fn (Position $record): array => self::scoutActivateTableExtraAttributes($record))
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && auth()->user() !== null
                && app(SquadContext::class)->userCanInAnySquad(auth()->user(), 'position.activate'))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('activate', $record) ?? false)
            ->requiresConfirmation(fn (Position $record): bool => self::scoutExceedsRiskLimit($record))
            ->modalHeading(fn (Position $record): string => self::scoutExceedsRiskLimit($record)
                ? 'Risicomanagement overschreden'
                : 'Scout activeren als positie')
            ->modalDescription(fn (Position $record): string => self::scoutExceedsRiskLimit($record)
                ? self::scoutRiskOverrideDescription($record)
                : 'Vul je werkelijke fill en aantal in. De broker stop-loss wordt automatisch gezet op de berekende SL.')
            ->modalSubmitActionLabel(fn (Position $record): string => self::scoutExceedsRiskLimit($record)
                ? 'Toch doordrukken'
                : 'Activeren')
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
                Placeholder::make('planned_risk_preview')
                    ->label('Gepland risico')
                    ->visible(fn (Position $record): bool => $record->planned_risk_dollars !== null)
                    ->content(function (Position $record): HtmlString {
                        $guard = self::resolveScoutRiskGuardState($record);
                        $plannedRisk = (float) $record->planned_risk_dollars;
                        $colorClass = $guard['exceeds']
                            ? 'text-danger-600 dark:text-danger-400'
                            : 'text-success-600 dark:text-success-400';

                        $lines = ['<span class="text-lg font-semibold '.$colorClass.'">$'.number_format($plannedRisk, 2).'</span>'];

                        if ($guard['riskPercentOfBankroll'] !== null) {
                            $riskPctLabel = rtrim(rtrim(number_format($guard['riskPercentOfBankroll'], 1), '0'), '.');
                            $lines[] = '<span class="block text-sm text-gray-600 dark:text-gray-400 mt-1">'.$riskPctLabel.'% van bankroll</span>';
                        }

                        if ($record->planned_risk_percentage !== null) {
                            $tradeRiskLabel = rtrim(rtrim(number_format((float) $record->planned_risk_percentage, 2), '0'), '.');
                            $lines[] = '<span class="block text-sm text-gray-600 dark:text-gray-400 mt-1">'.$tradeRiskLabel.'% daling tot SL</span>';
                        }

                        if ($guard['exceeds'] && $guard['overByPercentPoints'] !== null) {
                            $overLabel = rtrim(rtrim(number_format($guard['overByPercentPoints'], 1), '0'), '.');
                            $lines[] = '<span class="block text-sm text-danger-600 dark:text-danger-400 mt-1">'.$overLabel.'% boven limiet</span>';
                        }

                        if ($record->quantity !== null) {
                            $lines[] = '<span class="block text-sm text-gray-600 dark:text-gray-400 mt-1">'.number_format((float) $record->quantity, 0).' stuks</span>';
                        }

                        return new HtmlString(implode('', $lines));
                    }),
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

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    public static function toggleMarketOpenReminder(): Action
    {
        return Action::make('toggle_market_open_reminder')
            ->label(fn (Position $record): string => $record->market_open_reminder_on !== null
                ? 'Reminder uit'
                : 'Herinner bij market open')
            ->tooltip(fn (Position $record): string => $record->market_open_reminder_on !== null
                ? 'Annuleer market open reminder'
                : 'Plan Telegram-reminder voor volgende handelsdag (15:35)')
            ->icon(fn (Position $record): string => $record->market_open_reminder_on !== null
                ? 'heroicon-s-bell-alert'
                : 'heroicon-o-bell-alert')
            ->color('info')
            ->iconButton()
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() !== ScoutPipelineStatus::Active
                && $record->entry_price !== null)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record): void {
                if ($record->market_open_reminder_on !== null) {
                    $record->clearMarketOpenReminder();

                    FilamentNotifier::send(
                        title: 'Reminder geannuleerd',
                        body: "{$record->ticker} staat weer op Scout.",
                    );

                    return;
                }

                $record->scheduleMarketOpenReminder();

                FilamentNotifier::send(
                    title: 'Reminder gepland',
                    body: sprintf(
                        '%s: Telegram op %s om %s.',
                        $record->ticker,
                        $record->fresh()->market_open_reminder_on?->format('d-m-Y') ?? 'volgende handelsdag',
                        config('vestix.market_open_reminder.time', '15:35'),
                    ),
                );
            });
    }

    public static function markBuyStopPlaced(): Action
    {
        return Action::make('mark_buy_stop_placed')
            ->label('Order geplaatst')
            ->tooltip('Markeer als Active — buy-stop staat bij je broker')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->iconButton()
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() !== ScoutPipelineStatus::Active)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record): void {
                $record->update([
                    'broker_order_status' => BrokerOrderStatus::Pending,
                    'market_open_reminder_on' => null,
                ]);

                FilamentNotifier::send(
                    title: 'Order gemarkeerd als Active',
                    body: "{$record->ticker} staat nu op Active in je radar.",
                );
            });
    }

    public static function clearBuyStop(): Action
    {
        return Action::make('clear_buy_stop')
            ->label('Terug naar scout')
            ->tooltip('Order bij broker geannuleerd of gewijzigd')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->iconButton()
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() === ScoutPipelineStatus::Active)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record): void {
                $record->update(['broker_order_status' => BrokerOrderStatus::Scout]);

                FilamentNotifier::send(
                    title: 'Terug naar scout',
                    body: "{$record->ticker} staat weer op Scout in je radar.",
                );
            });
    }

    /**
     * @param  class-string<resource>  $scoutResourceClass
     */
    public static function cloneTarget(string $scoutResourceClass = ScoutResource::class): Action
    {
        return Action::make('clone_target')
            ->label('Kloon Target')
            ->tooltip('Kopieer ticker, entry en stop-loss naar je privé-radar')
            ->icon('heroicon-o-document-duplicate')
            ->iconButton()
            ->color('info')
            ->visible(fn (Position $record): bool => auth()->user()?->can('clone', $record) ?? false)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('clone', $record) ?? false)
            ->action(function (Position $record, Action $action) use ($scoutResourceClass): void {
                $clone = $record->cloneForUser(auth()->user());

                FilamentNotifier::send(
                    title: 'Target gekloond',
                    body: "{$clone->ticker} staat nu in je privé-radar.",
                );

                $action->successRedirectUrl($scoutResourceClass::getUrl('edit', ['record' => $clone]));
            });
    }

    public static function shareSuccess(): Action
    {
        return Action::make('share_success')
            ->label('Deel succes')
            ->tooltip('Genereer een branded share-card (geen dollarbedragen)')
            ->icon('heroicon-o-share')
            ->color('info')
            ->visible(fn (Position $record): bool => self::canSharePosition($record))
            ->modalHeading('Deel je trade')
            ->modalDescription('Privacy-safe kaart: alleen ticker, ROI % en prijzen per aandeel.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.share-card-modal', [
                    'card' => ShareCardDataFactory::fromPosition($record->loadMissing('asset')),
                ])->render()
            ));
    }

    public static function shareSetup(): Action
    {
        return Action::make('share_setup')
            ->label('Deel setup')
            ->tooltip(sprintf('Genereer een branded share-card voor je A++ setup (%d/%d)', ScoutSetupScorecard::maxPoints(), ScoutSetupScorecard::maxPoints()))
            ->icon('heroicon-o-share')
            ->color('info')
            ->visible(fn (Position $record): bool => self::canShareScout($record))
            ->modalHeading('Deel je A++ setup')
            ->modalDescription(sprintf(
                'Privacy-safe kaart: ticker, setup-score %d/%d, Close/SMA/RSI en geplande entry/SL per aandeel.',
                ScoutSetupScorecard::maxPoints(),
                ScoutSetupScorecard::maxPoints(),
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.share-card-modal', [
                    'card' => ShareCardDataFactory::fromScout($record->loadMissing('asset')),
                    'template' => 'share-cards.scout-square',
                ])->render()
            ));
    }

    public static function canShareScout(Position $record): bool
    {
        if ($record->status !== 'scout') {
            return false;
        }

        $score = $record->evaluateSetupScore();

        return $score['grade'] === 'A++' && $score['totalPoints'] === ScoutSetupScorecard::maxPoints();
    }

    public static function canSharePosition(Position $record): bool
    {
        if ($record->status === 'open') {
            return $record->isFreerideSecured();
        }

        if ($record->status === 'closed') {
            return $record->unrealized_pnl_percentage > 0;
        }

        return false;
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
                $wasStoppedOut = $record->action_command === 'STOPPED OUT';

                $record->archiveWithExitPrice(
                    (float) $data['exit_price'],
                    $data['exit_chart_screenshot_path'] ?? null,
                );

                if ($wasStoppedOut) {
                    PositionLiquidated::dispatch($record->fresh());
                }

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
        $sl = StopLossProtocol::resolve($record);

        if ($sl === null) {
            return '— (haal eerst marktdata op)';
        }

        return '$'.number_format($sl, 2);
    }

    /**
     * @return array{
     *     riskLimit: ?float,
     *     riskPercentOfBankroll: ?float,
     *     exceeds: bool,
     *     overByPercentPoints: ?float,
     *     limitPercent: float
     * }
     */
    private static function resolveScoutRiskGuardState(Position $record): array
    {
        $user = auth()->user();
        $bankroll = $user?->trading_bankroll !== null ? (float) $user->trading_bankroll : null;
        $limitPercent = (float) ($user?->default_risk_percent ?? 1);
        $riskLimit = PositionSizing::resolveRiskLimitFromProfile($bankroll, $limitPercent);
        $plannedRisk = $record->planned_risk_dollars !== null ? (float) $record->planned_risk_dollars : null;
        $riskPercentOfBankroll = ($plannedRisk !== null && $bankroll !== null && $bankroll > 0)
            ? PositionSizing::riskAsPercentOfBankroll($plannedRisk, $bankroll)
            : null;
        $overByPercentPoints = $riskPercentOfBankroll !== null
            ? PositionSizing::overLimitByPercentPoints($riskPercentOfBankroll, $limitPercent)
            : null;

        return [
            'riskLimit' => $riskLimit,
            'riskPercentOfBankroll' => $riskPercentOfBankroll,
            'exceeds' => $plannedRisk !== null && PositionSizing::exceedsRiskLimit($plannedRisk, $riskLimit),
            'overByPercentPoints' => $overByPercentPoints,
            'limitPercent' => $limitPercent,
        ];
    }

    private static function scoutExceedsRiskLimit(Position $record): bool
    {
        return self::resolveScoutRiskGuardState($record)['exceeds'];
    }

    private static function scoutRiskOverrideDescription(Position $record): string
    {
        $guard = self::resolveScoutRiskGuardState($record);
        $plannedRisk = (float) $record->planned_risk_dollars;
        $limitPercentLabel = rtrim(rtrim(number_format($guard['limitPercent'], 2), '0'), '.');

        if ($guard['riskLimit'] === null) {
            return 'Je staat op het punt om je risicomanagement te breken. Wil je dit toch doorzetten?';
        }

        return 'Je riskeert $'.number_format($plannedRisk, 2)
            .', terwijl je limiet $'.number_format($guard['riskLimit'], 2)
            ." is ({$limitPercentLabel}% van bankroll). Wil je dit toch doorzetten of je inleg aanpassen?";
    }

    /**
     * @return array<string, string>
     */
    private static function scoutActivateTableExtraAttributes(Position $record): array
    {
        $classes = ['vestix-activate-scout-btn'];

        if (
            ($record->signal_low !== null || $record->latest_close_price !== null)
            && $record->latest_sma_20 !== null
            && $record->scout_rsi !== null
        ) {
            $score = $record->evaluateSetupScore();

            if ($score['hardFailReasons'] === [] && $score['grade'] === 'A++') {
                $classes[] = 'scout-activate-a-plus';
            }
        }

        return ['class' => implode(' ', $classes)];
    }
}
