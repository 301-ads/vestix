<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Enums\BrokerOrderStatus;
use App\Enums\ScoutPipelineStatus;
use App\Events\PositionLiquidated;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Services\SquadContext;
use App\Support\BrokerOrderTicket;
use App\Support\ChartScreenshotUpload;
use App\Support\EarningsExitDisplay;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFetchDispatcher;
use App\Support\MarketDataFreshness;
use App\Support\PositionSizing;
use App\Support\ScoutSetupAlertService;
use App\Support\ScoutSetupScorecard;
use App\Support\ShareCardDataFactory;
use App\Support\ScaleOutDisplay;
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
            ->tooltip(fn (Position $record): string => self::scoutActivationTooltip($record))
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->extraAttributes(fn (Position $record): array => self::scoutActivateTableExtraAttributes($record))
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && auth()->user() !== null
                && app(SquadContext::class)->userCanInAnySquad(auth()->user(), 'position.activate')
                && $record->scoutPipelineStatus() === ScoutPipelineStatus::Active)
            ->disabled(fn (Position $record): bool => self::scoutActivationDisabled($record))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('activate', $record) ?? false)
            ->requiresConfirmation(fn (Position $record): bool => self::scoutExceedsRiskLimit($record))
            ->modalHeading(fn (Position $record): string => self::scoutExceedsRiskLimit($record)
                ? 'Risicomanagement overschreden'
                : 'Scout activeren als positie')
            ->modalDescription(fn (Position $record): string => self::scoutExceedsRiskLimit($record)
                ? self::scoutRiskOverrideDescription($record)
                : 'Vul je werkelijke fill en aantal in. Na activatie verschijnt een actie om de stop-loss bij je broker in te stellen.')
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
                TextInput::make('target_1_rr')
                    ->label('Target 1 R/R')
                    ->numeric()
                    ->minValue(0.1)
                    ->step(0.1)
                    ->default(fn (): float => Position::defaultTarget1Rr()),
                TextInput::make('first_tranche_fraction')
                    ->label('Eerste tranche (fractie)')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(1)
                    ->step(0.01)
                    ->default(fn (): float => Position::defaultFirstTrancheFraction())
                    ->helperText('0.5 = 50% van de positie op Target 1'),
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
                Placeholder::make('order_plan_preview')
                    ->label('Order Plan')
                    ->visible(fn (Position $record): bool => $record->target_1_price !== null)
                    ->content(fn (Position $record): HtmlString => ScaleOutDisplay::orderPlanHtml($record)),
            ])
            ->action(function (Position $record, array $data): void {
                $record->activateAsPosition(
                    (float) $data['entry_price'],
                    (float) $data['quantity'],
                    isset($data['target_1_rr']) ? (float) $data['target_1_rr'] : null,
                    isset($data['first_tranche_fraction']) ? (float) $data['first_tranche_fraction'] : null,
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
            ->tooltip(fn (Position $record): string => $record->entry_price === null
                ? 'Vul eerst je buy-stop entry in'
                : ($record->market_open_reminder_on !== null
                    ? 'Annuleer market open reminder'
                    : 'Plan Telegram-reminder voor volgende handelsdag (15:35)'))
            ->icon(fn (Position $record): string => $record->market_open_reminder_on !== null
                ? 'heroicon-s-bell-alert'
                : 'heroicon-o-bell-alert')
            ->color('info')
            ->iconButton()
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() !== ScoutPipelineStatus::Active)
            ->disabled(fn (Position $record): bool => $record->entry_price === null)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record): void {
                if ($record->entry_price === null) {
                    FilamentNotifier::send(
                        title: 'Entry ontbreekt',
                        body: 'Vul eerst je buy-stop entry in via Bewerken.',
                        status: 'warning',
                    );

                    return;
                }

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

    public static function markBuyStopPlaced(bool $iconButton = true): Action
    {
        $action = Action::make('mark_buy_stop_placed')
            ->label(fn (Position $record): string => $record->usesIbkrWorkflow()
                ? 'Order plaatsen'
                : 'Order geplaatst')
            ->tooltip(fn (Position $record): string => self::markBuyStopTooltip($record))
            ->icon(fn (Position $record): string => $record->usesIbkrWorkflow()
                ? 'heroicon-o-clipboard-document-list'
                : 'heroicon-o-clock')
            ->color(fn (Position $record): string => $record->usesIbkrWorkflow() ? 'primary' : 'warning')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() !== ScoutPipelineStatus::Active)
            ->disabled(fn (Position $record): bool => ! $record->hasCompleteBracketPlan())
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation(fn (Position $record): bool => $record->usesIbkrWorkflow())
            ->modalHeading(fn (Position $record): string => $record->usesIbkrWorkflow()
                ? BrokerOrderTicket::forIbkrBracket($record)['title']
                : 'Order geplaatst')
            ->modalIcon(fn (Position $record): ?HtmlString => $record->usesIbkrWorkflow()
                ? BrokerOrderTicket::modalIcon($record)
                : null)
            ->modalIconColor('gray')
            ->extraModalWindowAttributes(fn (Position $record): array => $record->usesIbkrWorkflow()
                ? ['class' => 'vestix-broker-order-modal']
                : [])
            ->modalContent(fn (Position $record): ?HtmlString => $record->usesIbkrWorkflow()
                ? new HtmlString(
                    view('filament.positions.broker-order-ticket', [
                        'ticket' => BrokerOrderTicket::forIbkrBracket($record),
                    ])->render()
                )
                : null)
            ->modalSubmitActionLabel(fn (Position $record): string => $record->usesIbkrWorkflow()
                ? BrokerOrderTicket::forIbkrBracket($record)['submit_label']
                : 'Bevestigen')
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $record->update([
                    'broker_order_status' => BrokerOrderStatus::Pending,
                    'market_open_reminder_on' => null,
                ]);

                FilamentNotifier::send(
                    title: $record->usesIbkrWorkflow()
                        ? 'Bracket order gemarkeerd'
                        : 'Order gemarkeerd als Active',
                    body: "{$record->ticker} staat nu op Active in je radar.",
                );
            });

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    private static function markBuyStopTooltip(Position $record): string
    {
        if (! $record->hasCompleteBracketPlan()) {
            return 'Vul eerst entry, aantal en marktdata in of haal data op';
        }

        return $record->usesIbkrWorkflow()
            ? 'Toon IBKR bracket order plan voor TradingView'
            : 'Markeer als Active — buy-stop staat bij je broker';
    }

    public static function clearBuyStop(bool $iconButton = true): Action
    {
        $action = Action::make('clear_buy_stop')
            ->label('Order annuleren')
            ->tooltip('Order bij broker geannuleerd — terug naar Pending')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() === ScoutPipelineStatus::Active)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Order annuleren')
            ->modalDescription('Bevestig dat je de order bij je broker hebt geannuleerd. De scout gaat terug naar Pending.')
            ->action(function (Position $record): void {
                $record->update(['broker_order_status' => BrokerOrderStatus::Scout]);

                FilamentNotifier::send(
                    title: 'Order geannuleerd',
                    body: "{$record->ticker} staat weer op Pending — plaats opnieuw een order wanneer je klaar bent.",
                );
            });

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    public static function rolloverBuyStop(): Action
    {
        return Action::make('rollover_buy_stop')
            ->label('Laat staan (Rollover)')
            ->tooltip('Order opnieuw bij broker gezet voor vandaag')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->buy_stop_review_required_on !== null
                && $record->isOwnedBy(auth()->user()))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Buy-stop rollover')
            ->modalDescription('Bevestig dat je de buy-stop opnieuw bij je broker hebt gezet voor vandaag.')
            ->action(function (Position $record): void {
                $record->rolloverBuyStop();

                FilamentNotifier::send(
                    title: 'Buy-stop rollover',
                    body: "{$record->ticker} staat weer op Active in je radar.",
                );
            });
    }

    public static function editBuyStopEntry(string $scoutResourceClass): Action
    {
        return Action::make('edit_buy_stop_entry')
            ->label('Wijzig entry')
            ->tooltip('Pas entry en signal-cijfers aan')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->buy_stop_review_required_on !== null
                && $record->isOwnedBy(auth()->user()))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->url(fn (Position $record): string => $scoutResourceClass::getUrl('edit', ['record' => $record]));
    }

    public static function cancelBuyStopSetup(): Action
    {
        return Action::make('cancel_buy_stop_setup')
            ->label('Annuleer setup')
            ->tooltip('Setup is niet meer geldig — verwijder van radar')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->buy_stop_review_required_on !== null
                && $record->isOwnedBy(auth()->user()))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Setup annuleren')
            ->modalDescription('De scout wordt van je radar verwijderd. Zorg dat je de order ook bij je broker hebt geannuleerd.')
            ->action(function (Position $record): void {
                $ticker = $record->ticker;
                $record->cancelScoutSetup();

                FilamentNotifier::send(
                    title: 'Setup geannuleerd',
                    body: "{$ticker} is van je radar verwijderd.",
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

    public static function promoteToA(): Action
    {
        return Action::make('promote_to_a')
            ->label('Promoveer naar A')
            ->tooltip('Bevestig dat deze setup een A-grade setup is')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->iconButton()
            ->visible(fn (Position $record): bool => self::canPromoteToA($record))
            ->requiresConfirmation()
            ->modalHeading('Promoveer naar A')
            ->modalDescription('Je bevestigt dat deze setup de A-grade kwaliteitsdrempel haalt (≥8 punten, geen hard fails).')
            ->modalSubmitActionLabel('Bevestig A')
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record): void {
                $record->promoteToA();

                FilamentNotifier::send(
                    title: 'A SETUP bevestigd',
                    body: "{$record->ticker} is handmatig gepromoveerd naar A.",
                );
            });
    }

    public static function canPromoteToA(Position $record): bool
    {
        if (
            $record->status !== 'scout'
            || $record->trader_promoted_a
            || $record->trader_promoted_a_plus
        ) {
            return false;
        }

        $score = ScoutSetupScorecard::evaluate(self::algorithmicScorecardInputs($record));

        return $score['hardFailReasons'] === []
            && $score['totalPoints'] >= 8;
    }

    public static function promoteToAPlus(): Action
    {
        return Action::make('promote_to_a_plus')
            ->label('Promoveer naar A++')
            ->tooltip('Visuele bevestiging — jij bepaalt of dit een perfecte sniper-setup is')
            ->icon('heroicon-o-star')
            ->color('success')
            ->iconButton()
            ->visible(fn (Position $record): bool => self::canPromoteToAPlus($record))
            ->requiresConfirmation()
            ->modalHeading('Promoveer naar A++')
            ->modalDescription('Je bevestigt visueel dat deze setup de maximale sniper-kwaliteit heeft. Dit ontgrendelt share-card en A++-styling.')
            ->modalSubmitActionLabel('Bevestig A++')
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record): void {
                $record->promoteToAPlus();
                app(ScoutSetupAlertService::class)->notifyManualAPlusPromotion($record->fresh());

                FilamentNotifier::send(
                    title: 'A++ SETUP bevestigd',
                    body: "{$record->ticker} is handmatig gepromoveerd naar A++.",
                );
            });
    }

    public static function canPromoteToAPlus(Position $record): bool
    {
        if ($record->status !== 'scout' || $record->trader_promoted_a_plus) {
            return false;
        }

        $score = ScoutSetupScorecard::evaluate(self::algorithmicScorecardInputs($record));

        return $score['hardFailReasons'] === []
            && $score['totalPoints'] === ScoutSetupScorecard::maxPoints();
    }

    /**
     * @return array<string, mixed>
     */
    private static function algorithmicScorecardInputs(Position $record): array
    {
        return [
            'signal_low' => $record->signal_low,
            'latest_open_price' => $record->latest_open_price,
            'latest_close_price' => $record->latest_close_price,
            'latest_sma_20' => $record->latest_sma_20,
            'sma_20_ten_days_ago' => $record->sma_20_ten_days_ago,
            'latest_sma_50' => $record->latest_sma_50,
            'scout_rsi' => $record->scout_rsi,
            'bounce_volume_above_average' => $record->bounce_volume_above_average,
            'relative_volume' => $record->relative_volume,
            'bounce_day_volume' => $record->bounce_day_volume,
            'volume_sma_20' => $record->volume_sma_20,
            'sector_etf' => $record->sector_etf,
            'sector_trend_positive' => $record->sector_trend_positive,
            'pre_bounce_extension_atr' => $record->pre_bounce_extension_atr,
            'days_until_earnings' => $record->daysUntilEarnings(),
        ];
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

    public static function markInitialSlPlaced(): Action
    {
        return Action::make('mark_initial_sl_placed')
            ->label('Update')
            ->tooltip('Bevestig dat de stop-loss bij je broker staat')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'open'
                && ! $record->hasInitialSlPlaced())
            ->requiresConfirmation()
            ->modalHeading(fn (Position $record): string => BrokerOrderTicket::forInitialStopLoss($record)['title'])
            ->modalIcon(fn (Position $record): HtmlString => BrokerOrderTicket::modalIcon($record))
            ->modalIconColor('gray')
            ->extraModalWindowAttributes(['class' => 'vestix-broker-order-modal'])
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.broker-order-ticket', [
                    'ticket' => BrokerOrderTicket::forInitialStopLoss($record),
                ])->render()
            ))
            ->modalSubmitActionLabel('Stop-Loss geplaatst')
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $record->markInitialSlPlaced();

                FilamentNotifier::send(
                    title: 'Stop-Loss gemarkeerd',
                    body: "{$record->ticker}: de broker-to-do is afgevinkt.",
                );
            });
    }

    public static function markAsUpdated(): Action
    {
        return Action::make('mark_as_updated')
            ->label('Update')
            ->tooltip('Stop-Loss bijwerken naar berekende SL')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'open'
                && $record->hasInitialSlPlaced()
                && $record->action_command === 'UPDATE')
            ->requiresConfirmation()
            ->modalHeading(fn (Position $record): string => BrokerOrderTicket::forStopLossUpdate($record)['title'])
            ->modalIcon(fn (Position $record): HtmlString => BrokerOrderTicket::modalIcon($record))
            ->modalIconColor('gray')
            ->extraModalWindowAttributes(['class' => 'vestix-broker-order-modal'])
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.broker-order-ticket', [
                    'ticket' => BrokerOrderTicket::forStopLossUpdate($record),
                ])->render()
            ))
            ->modalSubmitActionLabel('Stop-Loss Updated')
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $record->update(['current_sl' => $record->new_sl]);

                FilamentNotifier::send(title: 'Stop-Loss geüpdatet!');
            });
    }

    public static function markTarget1LimitPlaced(): Action
    {
        return Action::make('mark_limit_placed')
            ->label('Update')
            ->tooltip(fn (Position $record): string => $record->userUsesRevolutWorkflow()
                ? 'Bevestig dat Target 1 is bereikt (Telegram of Revolut-notificatie)'
                : 'Bevestig dat de limit sell bij je broker staat')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'open'
                && $record->isTarget1Hit()
                && ! $record->hasTarget1LimitPlaced()
                && ! $record->suppressesLimitSellTodo())
            ->requiresConfirmation()
            ->modalHeading(fn (Position $record): string => BrokerOrderTicket::forLimitSell($record)['title'])
            ->modalIcon(fn (Position $record): HtmlString => BrokerOrderTicket::modalIcon($record))
            ->modalIconColor('gray')
            ->extraModalWindowAttributes(['class' => 'vestix-broker-order-modal'])
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.broker-order-ticket', [
                    'ticket' => BrokerOrderTicket::forLimitSell($record),
                ])->render()
            ))
            ->modalSubmitActionLabel(fn (Position $record): string => BrokerOrderTicket::forLimitSell($record)['submit_label'])
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $record->markTarget1LimitPlaced();

                FilamentNotifier::send(
                    title: $record->userUsesRevolutWorkflow()
                        ? 'Target 1 bevestigd'
                        : 'Limit sell gemarkeerd',
                    body: "{$record->ticker}: de broker-to-do is afgevinkt.",
                );
            });
    }

    public static function scaleOut(): Action
    {
        return Action::make('scale_out')
            ->label('Scale-out uitgevoerd')
            ->tooltip('Log gedeeltelijke verkoop op Target 1 — stop gaat naar breakeven')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'open'
                && ! $record->hasScaledOut()
                && ! $record->isAutoRunnerBypass()
                && ($record->isTarget1Hit() || $record->hasTarget1LimitPlaced()))
            ->modalHeading('Target 1 — gedeeltelijke verkoop')
            ->modalDescription('Log de werkelijke fill bij je broker. Je stop-loss wordt automatisch naar breakeven (entry) verplaatst.')
            ->schema([
                TextInput::make('fill_price')
                    ->label('Werkelijke verkoopprijs')
                    ->numeric()
                    ->required()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->default(fn (Position $record): ?float => $record->target_1_price),
                TextInput::make('quantity')
                    ->label('Aantal verkocht')
                    ->numeric()
                    ->required()
                    ->inputMode('decimal')
                    ->step('any')
                    ->minValue(0.000001)
                    ->default(fn (Position $record): ?float => $record->target_1_quantity),
                Placeholder::make('breakeven_note')
                    ->label('Na verkoop')
                    ->content('Stop-loss → entry (breakeven). Runner blijft trailen onder SMA 20.'),
            ])
            ->action(function (Position $record, array $data): void {
                $record->scaleOut(
                    (float) $data['fill_price'],
                    (float) $data['quantity'],
                );

                FilamentNotifier::send(
                    title: 'Target 1 gelogd',
                    body: sprintf(
                        '%s: +$%s gerealiseerd. Runner op breakeven.',
                        $record->ticker,
                        number_format((float) $record->fresh()->realized_pnl, 2),
                    ),
                );
            })
            ->after(function ($livewire): void {
                if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData();
                }
            });
    }

    public static function holdThroughEarnings(): Action
    {
        return Action::make('hold_through_earnings')
            ->label('Doorgaan als runner')
            ->tooltip('Houd de positie open door earnings heen — earnings-exit alerts stoppen')
            ->icon('heroicon-o-arrow-trending-up')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->status === 'open'
                && $record->requiresEarningsExit())
            ->requiresConfirmation()
            ->modalHeading('Doorgaan als runner na earnings?')
            ->modalDescription(fn (Position $record): string => sprintf(
                '%s blijft open en trailt verder onder SMA 20. Earnings-exit alerts en de archiveer-actie verdwijnen voor deze earnings-ronde.',
                $record->ticker,
            ))
            ->modalSubmitActionLabel('Doorgaan als runner')
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $record->acknowledgeHeldThroughEarnings();

                FilamentNotifier::send(
                    title: 'Runner na earnings',
                    body: sprintf(
                        '%s: earnings-exit uitgesteld. Positie blijft trailen.',
                        $record->ticker,
                    ),
                );
            })
            ->after(function ($livewire): void {
                if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData();
                }
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

    public static function scoutActivationDisabled(Position $record): bool
    {
        return EarningsExitDisplay::isWithinAlertWindow($record)
            || MarketDataFreshness::isPositionSyncInProgress($record->id)
            || MarketDataFreshness::isSyncInProgress();
    }

    public static function scoutActivationTooltip(Position $record): string
    {
        if (EarningsExitDisplay::isWithinAlertWindow($record)) {
            $daysUntil = $record->daysUntilEarnings();

            return $daysUntil !== null
                ? "Promotie geblokkeerd: earnings over {$daysUntil} dagen (dead zone ≤14 dagen)"
                : 'Promotie geblokkeerd: earnings binnen 14 dagen';
        }

        if (MarketDataFreshness::isPositionSyncInProgress($record->id)
            || MarketDataFreshness::isSyncInProgress()) {
            return 'Marktdata wordt opgehaald — even geduld';
        }

        return 'Zet scout om naar open positie met berekende stop-loss';
    }
}
