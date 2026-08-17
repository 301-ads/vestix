<?php

namespace App\Filament\Resources\Positions\Tables;

use App\Enums\AutopsyTag;
use App\Enums\BrokerOrderStatus;
use App\Enums\ExecutionTruthState;
use App\Enums\GapHerplanAction;
use App\Enums\ScoutPipelineStatus;
use App\Events\PositionLiquidated;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Services\CloneAttributionService;
use App\Services\MarketDataFetcher;
use App\Services\SquadContext;
use App\Support\BrokerOrderTicket;
use App\Support\ChartScreenshotUpload;
use App\Support\EarningsExitDisplay;
use App\Support\EarningsExitSchedule;
use App\Support\FilamentNotifier;
use App\Support\IbkrFillPrice;
use App\Support\MarketDataFetchDispatcher;
use App\Support\MarketDataFreshness;
use App\Support\OrderPlanBroadcast;
use App\Support\PositionSizing;
use App\Support\ScaleOutDisplay;
use App\Support\ScoutSetupAlertService;
use App\Support\ScoutSetupScorecard;
use App\Support\ShareCardDataFactory;
use App\Support\StopLossProtocol;
use App\Support\TradeJournal;
use App\Support\UsMarketSession;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
                if ($record->status === 'open') {
                    $mark = app(MarketDataFetcher::class)->refreshOpenPositionLiveMark($record, force: true);

                    if ($mark !== null) {
                        $record->refresh();

                        if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
                            $livewire->refreshFormData(['latest_close_price']);
                        }
                    }
                }

                if (! MarketDataFetchDispatcher::dispatchPositionFetch($record)) {
                    return;
                }

                if (is_object($livewire) && method_exists($livewire, 'startPollingPositionMarketData')) {
                    $livewire->startPollingPositionMarketData();
                }
            });

        if ($syncButtonStyle) {
            $action
                ->label(fn (Position $record): string => MarketDataFreshness::isPositionSyncInProgress($record->id)
                    ? 'Bezig…'
                    : 'Sync')
                ->color('primary')
                ->outlined()
                ->extraAttributes(['class' => 'vestix-sync-btn vestix-sync-btn--green']);
        }

        return $action;
    }

    public static function refreshSignalCandle(): Action
    {
        return Action::make('refresh_signal_candle')
            ->label('Signaal')
            ->tooltip('Signaalkaars vernieuwen: nieuwste bounce/rejection → Low/High + entry')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->outlined()
            ->extraAttributes(['class' => 'vestix-sync-btn'])
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && (auth()->user()?->can('update', $record) ?? false))
            ->disabled(fn (Position $record): bool => MarketDataFreshness::isPositionSyncInProgress($record->id)
                || MarketDataFreshness::isSyncInProgress())
            ->requiresConfirmation()
            ->modalHeading('Signaalkaars vernieuwen?')
            ->modalDescription('Low/High en entry worden overschreven met de nieuwste bounce- of rejection-kaars uit de marktdata. Gebruik dit voor Order Plan-setups die vastzitten op een oude kaars.')
            ->modalSubmitActionLabel('Vernieuwen')
            ->action(function (Position $record, $livewire): void {
                $success = app(MarketDataFetcher::class)->refreshSignalCandle($record);

                if (! $success) {
                    FilamentNotifier::send(
                        'Signaalkaars niet bijgewerkt',
                        "Geen bruikbare bounce/rejection-kaars gevonden voor {$record->ticker}.",
                        'warning',
                    );

                    return;
                }

                $record->refresh();

                if ($record->status === 'scout') {
                    $scorecard = $record->evaluateSetupScore();
                    $record->persistLastSetupScorecard($scorecard);
                    $record->refresh();
                }

                FilamentNotifier::send(
                    'Signaalkaars bijgewerkt',
                    $record->signal_bar_date !== null
                        ? "{$record->ticker}: signaal van {$record->signal_bar_date->toDateString()}."
                        : "{$record->ticker}: signaalkaars vernieuwd.",
                );

                if (is_object($livewire) && method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData([
                        'signal_low',
                        'signal_high',
                        'signal_bar_date',
                        'detected_signal_bar_date',
                        'entry_price',
                        'quantity',
                        'latest_open_price',
                        'latest_close_price',
                        'latest_sma_20',
                        'sma_20_five_days_ago',
                        'sma_20_ten_days_ago',
                        'latest_sma_50',
                        'latest_atr_14',
                        'scout_rsi',
                        'bounce_volume_above_average',
                        'bounce_day_volume',
                        'avg_volume_30d',
                        'relative_volume',
                        'volume_sma_20',
                        'sector_etf',
                        'sector_close',
                        'sector_sma_50',
                        'sector_trend_positive',
                        'pre_bounce_extension_atr',
                        'last_setup_score',
                        'last_setup_grade',
                    ]);
                }
            });
    }

    public static function activateScout(bool $iconButton = true, bool $highlightAPlus = true): Action
    {
        $action = Action::make('activate_scout')
            ->label('Activeren')
            ->tooltip(fn (Position $record): string => self::scoutActivationTooltip($record))
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->extraAttributes(fn (Position $record): array => self::scoutActivateTableExtraAttributes($record, $highlightAPlus))
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && auth()->user() !== null
                && app(SquadContext::class)->userCanInAnySquad(auth()->user(), 'position.activate')
                && $record->scoutPipelineStatus() === ScoutPipelineStatus::Active)
            ->disabled(fn (Position $record): bool => self::scoutActivationDisabled($record))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('activate', $record) ?? false)
            ->requiresConfirmation(fn (Position $record): bool => self::scoutExceedsRiskLimit($record)
                || self::scoutEarningsOverrideRequired($record))
            ->modalHeading(fn (Position $record): string => match (true) {
                self::scoutExceedsRiskLimit($record) => 'Risicomanagement overschreden',
                self::scoutEarningsOverrideRequired($record) => 'Earnings-risico: toch activeren?',
                default => 'Scout activeren als positie',
            })
            ->modalDescription(fn (Position $record): string => match (true) {
                self::scoutExceedsRiskLimit($record) => self::scoutRiskOverrideDescription($record),
                self::scoutEarningsOverrideRequired($record) => self::scoutEarningsOverrideDescription($record),
                default => 'Vul je werkelijke IBKR-fill (avg) en aantal in — niet de geplande buy-stop of limit uit Order Plan. Bij partial fill: pas het aantal aan naar wat IBKR echt gevuld heeft (ook later via Edit).',
            })
            ->modalSubmitActionLabel(fn (Position $record): string => match (true) {
                self::scoutExceedsRiskLimit($record) => 'Toch doordrukken',
                self::scoutEarningsOverrideRequired($record) => 'Toch activeren',
                default => 'Activeren',
            })
            ->schema([
                Placeholder::make('planned_entry_reference')
                    ->label('Order Plan (alleen referentie)')
                    ->content(function (Position $record): HtmlString {
                        $stop = IbkrFillPrice::plannedBuyStop($record);
                        $limit = IbkrFillPrice::plannedLimit($record);
                        $parts = [];

                        if ($stop !== null) {
                            $parts[] = 'Buy-stop $'.number_format($stop, 2);
                        }

                        if ($limit !== null) {
                            $parts[] = 'Limit $'.number_format($limit, 2);
                        }

                        $text = $parts === []
                            ? 'Geen geplande buy-stop bekend.'
                            : implode(' · ', $parts).' — dit is géén fill.';

                        return new HtmlString(
                            '<span class="text-sm text-gray-600 dark:text-gray-400">'.$text.'</span>'
                        );
                    }),
                TextInput::make('entry_price')
                    ->label('Werkelijke fill (IBKR avg)')
                    ->numeric()
                    ->required()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->helperText('Gemiddelde fillprijs van je broker. Nooit de buy-stop of limit uit Order Plan.')
                    // Prefer Flex averageCost when present. Never default to planned buy-stop/limit.
                    ->default(fn (Position $record): ?float => IbkrFillPrice::suggestedFillForScout($record))
                    ->live(onBlur: true),
                TextInput::make('quantity')
                    ->label('Aantal')
                    ->numeric()
                    ->required()
                    ->inputMode('decimal')
                    ->step('any')
                    ->minValue(0.000001)
                    ->helperText('Bij partial fill: vul het werkelijke aantal in (niet het geplande).')
                    ->default(fn (Position $record): ?float => $record->quantity !== null
                        ? (float) $record->quantity
                        : null),
                TextInput::make('target_1_rr')
                    ->label('Target 1 R/R')
                    ->numeric()
                    ->minValue(0.1)
                    ->step(0.1)
                    ->default(fn (): float => Position::defaultTarget1Rr())
                    ->live(onBlur: true),
                TextInput::make('first_tranche_fraction')
                    ->label('Eerste tranche (fractie)')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(1)
                    ->step(0.01)
                    ->default(fn (): float => Position::defaultFirstTrancheFraction())
                    ->helperText('0.5 = 50% van de positie op Target 1'),
                Placeholder::make('target_1_raise_preview')
                    ->label('Take Profit na fill')
                    ->visible(function (Get $get, Position $record): bool {
                        return self::activationNeedsTarget1QtyHint($record, $get)
                            || self::activationTarget1RaisePrice($record, $get) !== null;
                    })
                    ->content(function (Get $get, Position $record): HtmlString {
                        $parts = [];
                        $quantity = self::numericActionValue($get('quantity'))
                            ?? ($record->quantity !== null ? (float) $record->quantity : null);
                        $fraction = self::numericActionValue($get('first_tranche_fraction'))
                            ?? $record->effective_first_tranche_fraction;

                        if (self::activationNeedsTarget1QtyHint($record, $get) && $quantity !== null) {
                            $tpQty = Position::wholeShareTrancheQuantity($quantity, $fraction);
                            $percent = (int) round($fraction * 100);

                            if ($tpQty !== null) {
                                $parts[] = '<p class="text-sm text-warning-600 dark:text-warning-400 font-medium">'
                                    .'TradingView zet Take Profit op 100%. Na activatie: wijzig het TP-aantal naar '
                                    .'<span class="font-semibold text-gray-950 dark:text-white">'
                                    .rtrim(rtrim(number_format($tpQty, 6, '.', ''), '0'), '.')
                                    .' stuks ('.$percent.'%)</span> zodat de runner blijft staan.</p>';
                            }
                        }

                        $newT1 = self::activationTarget1RaisePrice($record, $get);
                        $currentT1 = $record->copiedBracketTarget1Price();
                        $fill = self::numericActionValue($get('entry_price'));

                        if ($newT1 !== null && $currentT1 !== null && $fill !== null) {
                            $planned = $record->advisedEntryStop()
                                ?? ($record->entry_price !== null ? (float) $record->entry_price : null);
                            $delta = $newT1 - $currentT1;
                            $fillDelta = $planned !== null ? $fill - $planned : 0.0;
                            $verb = $delta >= 0 ? 'Verhoog' : 'Verlaag';

                            $parts[] = '<p class="text-sm text-warning-600 dark:text-warning-400 font-medium">'
                                .'Fill $'.number_format($fill, 2)
                                .($planned !== null
                                    ? ' is $'.number_format(abs($fillDelta), 2).($fillDelta >= 0 ? ' boven' : ' onder').' je order-stop $'.number_format($planned, 2)
                                    : '')
                                .'.</p>'
                                .'<p class="text-sm text-gray-600 dark:text-gray-300 mt-1">'.$verb.' Target 1 van $'
                                .number_format($currentT1, 2).' naar <span class="font-semibold text-gray-950 dark:text-white">$'
                                .number_format($newT1, 2).'</span> ('
                                .($delta >= 0 ? '+' : '').'$'.number_format($delta, 2)
                                .'). Na activeren krijg je een actie om dit 1-op-1 in je broker over te nemen.</p>';
                        }

                        return new HtmlString(implode('', $parts));
                    }),
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
                ? 'Uit Order Plan'
                : 'In Order Plan')
            ->tooltip(fn (Position $record): string => $record->entry_price === null
                ? 'Vul eerst je buy-stop entry in'
                : ($record->market_open_reminder_on !== null
                    ? 'Haal deze scout uit je Order Plan (winkelwagen + Telegram digest)'
                    : sprintf(
                        'Zet in Order Plan: winkelwagen + Telegram digest om %s',
                        config('vestix.execution_digest.time', '15:31'),
                    )))
            ->icon(fn (Position $record): string => $record->market_open_reminder_on !== null
                ? 'heroicon-s-shopping-cart'
                : 'heroicon-o-shopping-cart')
            ->color('info')
            ->iconButton()
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() !== ScoutPipelineStatus::Active)
            ->disabled(fn (Position $record): bool => $record->entry_price === null)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (Position $record, $livewire): void {
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
                        title: 'Uit Order Plan',
                        body: "{$record->ticker} staat niet meer in je Order Plan.",
                    );

                    OrderPlanBroadcast::dispatch($livewire);

                    return;
                }

                $record->scheduleMarketOpenReminder();

                FilamentNotifier::send(
                    title: 'In Order Plan',
                    body: sprintf(
                        '%s staat in je Order Plan (Telegram digest op %s om %s). Open het winkelwagen-icoon rechtsboven voor budgetverdeling.',
                        $record->ticker,
                        $record->fresh()->market_open_reminder_on?->format('d-m-Y') ?? 'volgende handelsdag',
                        config('vestix.execution_digest.time', '15:31'),
                    ),
                );

                OrderPlanBroadcast::dispatch($livewire);
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
            ->disabled(fn (Position $record): bool => ! $record->canMarkBuyStopPlaced())
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
                if (! $record->canMarkBuyStopPlaced()) {
                    $body = match (true) {
                        $record->isPendingVisualReview() => 'Zet eerst in Order Plan (winkelwagen) of wijs af.',
                        $record->isPlannedEntryThroughMarket() => $record->isShort()
                            ? 'Sell-stop ligt boven de koers — herprijs de signaalkaars.'
                            : 'Buy-stop ligt onder de koers — herprijs de signaalkaars.',
                        $record->isFailedBreakout() => $record->isShort()
                            ? 'Sell-stop is al geraakt — wacht op een nieuwe rejection.'
                            : 'Buy-stop is al geraakt — wacht op een nieuwe bounce.',
                        ($reasons = $record->shortSniperHardFailReasons()) !== [] => implode(' · ', $reasons),
                        default => 'Vul eerst entry, aantal en marktdata in of haal data op.',
                    };

                    FilamentNotifier::send(
                        title: 'Order geblokkeerd',
                        body: $body,
                        status: 'danger',
                    );

                    return;
                }

                $record->markSubmittedAtBroker();

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

        if ($record->isPendingVisualReview()) {
            return 'Zet eerst in Order Plan (winkelwagen) — dat is je visuele goedkeuring';
        }

        if ($record->isPlannedEntryThroughMarket()) {
            return $record->isShort()
                ? 'Sell-stop ligt boven de koers — herprijs de signaalkaars'
                : 'Buy-stop ligt onder de koers — herprijs de signaalkaars';
        }

        if ($record->isFailedBreakout()) {
            return $record->isShort()
                ? 'Sell-stop is al geraakt — wacht op een nieuwe rejection'
                : 'Buy-stop is al geraakt — wacht op een nieuwe bounce';
        }

        $hardFails = $record->shortSniperHardFailReasons();

        if ($hardFails !== []) {
            return 'Sniper-veto: '.implode(' · ', $hardFails);
        }

        return $record->usesIbkrWorkflow()
            ? 'Toon IBKR bracket order plan voor TradingView'
            : 'Markeer als Active — buy-stop staat bij je broker';
    }

    public static function clearBuyStop(bool $iconButton = true): Action
    {
        $action = Action::make('clear_buy_stop')
            ->label('Terug naar winkelwagen')
            ->tooltip('Haal uit Actief — terug naar Order Plan (winkelwagen)')
            ->icon('heroicon-o-shopping-cart')
            ->color('warning')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->isOwnedBy(auth()->user())
                && $record->scoutPipelineStatus() === ScoutPipelineStatus::Active)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Terug naar winkelwagen')
            ->modalDescription('Gebruik dit als de scout per ongeluk op Actief stond (bijv. na bulk “Naar Order Plan”). Bevestig dat er geen live order bij je broker staat.')
            ->action(function (Position $record, $livewire): void {
                $record->update([
                    'broker_order_status' => BrokerOrderStatus::Scout,
                    'broker_submitted_at' => null,
                    'execution_truth_state' => ExecutionTruthState::Planned,
                    'data_source_label' => 'planned',
                ]);

                // Zet terug in de winkelwagen (was vaak een foutieve bulk-add → “Actief”).
                if ($record->fresh()->entry_price !== null) {
                    $record->scheduleMarketOpenReminder();
                }

                FilamentNotifier::send(
                    title: 'Terug in Order Plan',
                    body: "{$record->ticker} staat weer in je winkelwagen — geen live broker-order meer.",
                );

                OrderPlanBroadcast::dispatch($livewire);
            });

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    public static function rolloverBuyStop(bool $iconButton = true): Action
    {
        $action = Action::make('rollover_buy_stop')
            ->label($iconButton ? 'Laat staan (Rollover)' : 'Rollover')
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

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    public static function editBuyStopEntry(string $scoutResourceClass, bool $iconButton = true): Action
    {
        $action = Action::make('edit_buy_stop_entry')
            ->label($iconButton ? 'Wijzig entry' : 'Wijzig')
            ->tooltip('Pas entry en signal-cijfers aan')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->buy_stop_review_required_on !== null
                && $record->isOwnedBy(auth()->user()))
            ->authorize(fn (Position $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->url(fn (Position $record): string => $scoutResourceClass::getUrl('edit', ['record' => $record]));

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    public static function cancelBuyStopSetup(bool $iconButton = true): Action
    {
        $action = Action::make('cancel_buy_stop_setup')
            ->label($iconButton ? 'Annuleer setup' : 'Annuleer')
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

        if ($iconButton) {
            $action->iconButton();
        }

        return $action;
    }

    /**
     * @param  class-string<resource>  $scoutResourceClass
     */
    public static function cloneTarget(string $scoutResourceClass = ScoutResource::class): Action
    {
        return Action::make('clone_target')
            ->label('Kloon Target')
            ->tooltip('Kopieer ticker, entry en stop-loss naar je privé-radar + Order Plan')
            ->icon('heroicon-o-document-duplicate')
            ->iconButton()
            ->color('info')
            ->visible(fn (Position $record): bool => auth()->user()?->can('clone', $record) ?? false)
            ->authorize(fn (Position $record): bool => auth()->user()?->can('clone', $record) ?? false)
            ->action(function (Position $record, Action $action) use ($scoutResourceClass): void {
                try {
                    $clone = $record->cloneForUser(auth()->user(), addToOrderPlan: true);
                } catch (\InvalidArgumentException $exception) {
                    FilamentNotifier::send(
                        title: 'Al op je radar',
                        body: $exception->getMessage(),
                        status: 'warning',
                    );
                    $action->halt();

                    return;
                }

                FilamentNotifier::send(
                    title: 'Target gekloond',
                    body: "{$clone->ticker} staat in je radar én Order Plan.",
                );

                $action->successRedirectUrl($scoutResourceClass::getUrl('edit', ['record' => $clone]));
            });
    }

    public static function viewClones(): Action
    {
        return Action::make('view_clones')
            ->label('Clones')
            ->tooltip('Wie nam deze setup over en hoe liep het')
            ->icon('heroicon-o-user-group')
            ->iconButton()
            ->color('gray')
            ->visible(fn (Position $record): bool => (int) ($record->clones_count ?? $record->clones()->count()) > 0)
            ->modalHeading(fn (Position $record): string => "Clones · {$record->ticker}")
            ->modalDescription('Privacy-safe: namen, status en ROI % — geen dollarbedragen.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Sluiten')
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.clone-attribution-modal', [
                    'rows' => app(CloneAttributionService::class)->cloneOutcomeRows($record),
                ])->render()
            ));
    }

    public static function gapHerplanReprice(): Action
    {
        return Action::make('gap_herplan_reprice')
            ->label('Herprijs')
            ->tooltip('Gap Reality: herprijs entry / buy-stop review')
            ->icon('heroicon-o-arrow-path')
            ->iconButton()
            ->color('warning')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->execution_digest_status?->isCancelled() === true
                && $record->gap_herplan_action === null)
            ->action(function (Position $record): void {
                $record->applyGapHerplan(GapHerplanAction::Reprice);
                FilamentNotifier::send(title: 'Herplan: herprijs', body: "{$record->ticker} klaar voor buy-stop review.");
            });
    }

    public static function gapHerplanSkip(): Action
    {
        return Action::make('gap_herplan_skip')
            ->label('Skip')
            ->tooltip('Gap Reality: skip vandaag')
            ->icon('heroicon-o-no-symbol')
            ->iconButton()
            ->color('gray')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->execution_digest_status?->isCancelled() === true
                && $record->gap_herplan_action === null)
            ->action(function (Position $record): void {
                $record->applyGapHerplan(GapHerplanAction::Skip);
                FilamentNotifier::send(title: 'Herplan: skip', body: "{$record->ticker} uit Order Plan voor vandaag.");
            });
    }

    public static function gapHerplanWait(): Action
    {
        return Action::make('gap_herplan_wait')
            ->label('Wacht')
            ->tooltip('Gap Reality: wacht op reclaim')
            ->icon('heroicon-o-clock')
            ->iconButton()
            ->color('info')
            ->visible(fn (Position $record): bool => $record->status === 'scout'
                && $record->execution_digest_status?->isCancelled() === true
                && $record->gap_herplan_action === null)
            ->action(function (Position $record): void {
                $record->applyGapHerplan(GapHerplanAction::Wait);
                FilamentNotifier::send(title: 'Herplan: wacht', body: "{$record->ticker} blijft in Order Plan.");
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

    public static function rejectVisualReview(): Action
    {
        return Action::make('reject_visual_review')
            ->label('Afwijzen')
            ->tooltip('Verwijder deze sniper-kandidaat uit Visuele Review')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->iconButton()
            ->visible(fn (Position $record): bool => self::canRejectVisualReview($record))
            ->requiresConfirmation()
            ->modalHeading('Scout afwijzen')
            ->modalDescription('Deze sniper-kandidaat verdwijnt uit Visuele Review.')
            ->modalSubmitActionLabel('Afwijzen')
            ->authorize(fn (Position $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->action(function (Position $record): void {
                $ticker = $record->ticker;
                $record->rejectVisualReview();

                FilamentNotifier::send(
                    title: 'Scout afgewezen',
                    body: "{$ticker} is verwijderd uit Visuele Review.",
                );
            });
    }

    public static function canRejectVisualReview(Position $record): bool
    {
        return $record->isPendingVisualReview();
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

        // 9–10 are already A; promote-to-A is only for strong B setups (8/10).
        return $score['hardFailReasons'] === []
            && $score['totalPoints'] >= 8
            && $score['totalPoints'] < ScoutSetupScorecard::maxPoints() - 1;
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
            'direction' => $record->tradeDirection(),
            'signal_low' => $record->signal_low,
            'signal_high' => $record->signal_high,
            'entry_price' => $record->entry_price,
            'latest_atr_14' => $record->latest_atr_14,
            'latest_open_price' => $record->latest_open_price,
            'latest_close_price' => $record->latest_close_price,
            'post_signal_high' => $record->post_signal_high,
            'post_signal_low' => $record->post_signal_low,
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
            'in_earnings_quarantine' => $record->isInEarningsEntryQuarantine(),
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

    public static function placeRunnerStopLoss(): Action
    {
        return Action::make('place_runner_sl')
            ->label('Update')
            ->tooltip('Nieuwe stop-loss voor de runner plaatsen — IBKR annuleerde de bracket-SL')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->primaryActionType() === Position::PRIMARY_ACTION_PLACE_RUNNER_SL)
            ->requiresConfirmation()
            ->modalHeading(fn (Position $record): string => BrokerOrderTicket::forRunnerStopLoss($record)['title'])
            ->modalIcon(fn (Position $record): HtmlString => BrokerOrderTicket::modalIcon($record))
            ->modalIconColor('gray')
            ->extraModalWindowAttributes(['class' => 'vestix-broker-order-modal'])
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.broker-order-ticket', [
                    'ticket' => BrokerOrderTicket::forRunnerStopLoss($record),
                ])->render()
            ))
            ->modalSubmitActionLabel(fn (Position $record): string => BrokerOrderTicket::forRunnerStopLoss($record)['submit_label'])
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $sl = $record->runnerStopLossPrice();
                $qty = $record->runnerQuantity();
                $record->applyRunnerSlPlaced();

                FilamentNotifier::send(
                    title: "{$record->ticker}: Runner-SL geplaatst",
                    body: $sl !== null && $qty !== null
                        ? sprintf(
                            '%s stuks op $%s',
                            rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.'),
                            number_format($sl, 2),
                        )
                        : null,
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
                && $record->action_command === 'UPDATE'
                && UsMarketSession::isTrailingStopReviewWindow())
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
                $newSl = $record->new_sl;
                $record->update(['current_sl' => $newSl]);

                FilamentNotifier::send(
                    title: "{$record->ticker}: Stop-Loss geüpdatet!",
                    body: $newSl !== null
                        ? 'Nieuwe SL: $'.number_format((float) $newSl, 2)
                        : null,
                );
            });
    }

    public static function adjustTarget1AtBroker(): Action
    {
        return Action::make('adjust_target_1')
            ->label('Update')
            ->tooltip('Take Profit bij broker aanpassen (50% en eventueel nieuwe prijs)')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->primaryActionType() === Position::PRIMARY_ACTION_ADJUST_TARGET_1)
            ->requiresConfirmation()
            ->modalHeading(fn (Position $record): string => BrokerOrderTicket::forTarget1Adjust($record)['title'])
            ->modalIcon(fn (Position $record): HtmlString => BrokerOrderTicket::modalIcon($record))
            ->modalIconColor('gray')
            ->extraModalWindowAttributes(['class' => 'vestix-broker-order-modal'])
            ->modalContent(fn (Position $record): HtmlString => new HtmlString(
                view('filament.positions.broker-order-ticket', [
                    'ticket' => BrokerOrderTicket::forTarget1Adjust($record),
                ])->render()
            ))
            ->modalSubmitActionLabel(fn (Position $record): string => BrokerOrderTicket::forTarget1Adjust($record)['submit_label'])
            ->modalCancelActionLabel('Annuleren')
            ->action(function (Position $record): void {
                $pending = $record->pendingTarget1LimitPrice();
                $qty = $record->needsTarget1QtyAdjust() ? $record->target_1_quantity : null;
                $record->applyTarget1BrokerAdjust();

                $parts = [];

                if ($qty !== null) {
                    $parts[] = rtrim(rtrim(number_format((float) $qty, 6, '.', ''), '0'), '.').' stuks (50%)';
                }

                if ($pending !== null) {
                    $parts[] = 'prijs $'.number_format($pending, 2);
                }

                FilamentNotifier::send(
                    title: "{$record->ticker}: Take Profit aangepast",
                    body: $parts !== [] ? implode(' · ', $parts) : null,
                );
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
            ->tooltip(fn (Position $record): string => $record->isAutoRunnerBypass()
                ? 'Log gedeeltelijke verkoop op Target 1 — stop blijft staan (al op/boven entry)'
                : 'Log gedeeltelijke verkoop op Target 1 — stop gaat naar breakeven')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Position $record): bool => $record->canLogScaleOut())
            ->modalHeading('Target 1 — gedeeltelijke verkoop')
            ->modalDescription(fn (Position $record): string => $record->isAutoRunnerBypass()
                ? 'Log de werkelijke fill bij je broker. Je stop-loss blijft staan (ligt al op of boven entry).'
                : ($record->usesIbkrWorkflow()
                    ? 'Log de werkelijke fill bij je broker. Vestix zet de stop op breakeven. IBKR annuleert de bracket-SL bij een TP-fill — plaats daarna een nieuwe stop voor de runner.'
                    : 'Log de werkelijke fill bij je broker. Je stop-loss wordt automatisch naar breakeven (entry) verplaatst.'))
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
                    ->content(fn (Position $record): string => $record->isAutoRunnerBypass()
                        ? 'Stop-loss blijft op de huidige prijs (al op/boven entry). Runner blijft trailen onder SMA 20.'
                        : ($record->usesIbkrWorkflow()
                            ? 'Stop-loss → entry (breakeven). IBKR: plaats daarna een nieuwe stop voor de runner — de bracket-SL is weg.'
                            : 'Stop-loss → entry (breakeven). Runner blijft trailen onder SMA 20.')),
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
                self::refreshEditRecordForm($livewire, [
                    'current_sl',
                    'scaled_out_price',
                    'scaled_out_quantity',
                    'scaled_out_at',
                    'realized_pnl',
                    'freeride_secured_at',
                ]);
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
                self::refreshEditRecordForm($livewire, [
                    'held_through_earnings_date',
                    'held_through_earnings_at',
                ]);
            });
    }

    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(fn (Position $record): string => $record->action_command === 'STOPPED OUT'
                ? 'Sluiten'
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
            ->modalDescription('Voor welke prijs is de trade definitief gesloten bij je broker? Voer daarna de verplichte Autopsie uit.')
            ->schema([
                TextInput::make('exit_price')
                    ->label('Werkelijke verkoopprijs')
                    ->numeric()
                    ->required()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->default(fn (Position $record): ?float => self::defaultExitPrice($record)),
                Select::make('autopsy_tag')
                    ->label('Autopsie')
                    ->options(AutopsyTag::options())
                    ->required()
                    ->native(false)
                    ->helperText('Trek procesdiscipline los van de financiële uitkomst.'),
                ChartScreenshotUpload::make('exit_chart_screenshot_path')
                    ->label('TradingView — exit')
                    ->imagePreviewHeight('160')
                    ->helperText('Optioneel: upload je exit-chart voor je trade journal. '.ChartScreenshotUpload::maxSizeLabel())
                    ->visible(fn (): bool => TradeJournal::enabled()),
            ])
            ->action(function (Position $record, array $data): void {
                $wasStoppedOut = $record->action_command === 'STOPPED OUT';

                $autopsy = AutopsyTag::from((string) $data['autopsy_tag']);

                $record->archiveWithExitPrice(
                    (float) $data['exit_price'],
                    $data['exit_chart_screenshot_path'] ?? null,
                    $autopsy,
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

    private static function numericActionValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function activationNeedsTarget1QtyHint(Position $record, Get $get): bool
    {
        if (! $record->usesIbkrWorkflow()) {
            return false;
        }

        $quantity = self::numericActionValue($get('quantity'))
            ?? ($record->quantity !== null ? (float) $record->quantity : null);

        return $quantity !== null && $quantity >= 2;
    }

    private static function activationTarget1RaisePrice(Position $record, Get $get): ?float
    {
        $fill = self::numericActionValue($get('entry_price'));

        if ($fill === null) {
            return null;
        }

        $rr = self::numericActionValue($get('target_1_rr')) ?? $record->effective_target_1_rr;

        return $record->target1RaisePriceForFill($fill, null, $rr);
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
        $limitPercent = (float) ($user?->defaultRiskPercentFor($record->tradeDirection()) ?? 1);
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
    private static function scoutActivateTableExtraAttributes(Position $record, bool $highlightAPlus = true): array
    {
        $classes = ['vestix-activate-scout-btn'];

        if (
            $highlightAPlus
            && ($record->signal_low !== null || $record->latest_close_price !== null)
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

    public static function scoutEarningsGateBlocks(Position $record): bool
    {
        return $record->isInEarningsEntryQuarantine()
            || EarningsExitDisplay::isWithinAlertWindow($record);
    }

    /**
     * Soft override removed (Vestix 2.0): earnings quarantine / runway is a hard NO TRADE gate.
     * Kept for backwards-compatible call sites; always false.
     */
    public static function scoutEarningsOverrideRequired(Position $record): bool
    {
        return false;
    }

    public static function scoutActivationDisabled(Position $record): bool
    {
        if (MarketDataFreshness::isPositionSyncInProgress($record->id)
            || MarketDataFreshness::isSyncInProgress()) {
            return true;
        }

        return self::scoutEarningsGateBlocks($record);
    }

    public static function scoutActivationTooltip(Position $record): string
    {
        if (MarketDataFreshness::isPositionSyncInProgress($record->id)
            || MarketDataFreshness::isSyncInProgress()) {
            return 'Marktdata wordt opgehaald — even geduld';
        }

        if ($record->isInEarningsEntryQuarantine()) {
            $tradingDays = EarningsExitSchedule::quarantineTradingDays();

            return "NO TRADE — earnings-quarantaine (±{$tradingDays} handelsdagen). Activatie geblokkeerd.";
        }

        if (EarningsExitDisplay::isWithinAlertWindow($record)) {
            $daysUntil = $record->daysUntilEarnings();

            return $daysUntil !== null
                ? "NO TRADE — earnings over {$daysUntil} dagen (runway ≤14 dagen). Activatie geblokkeerd."
                : 'NO TRADE — earnings binnen 14 dagen. Activatie geblokkeerd.';
        }

        return 'Zet scout om naar open positie met berekende stop-loss';
    }

    private static function scoutEarningsOverrideDescription(Position $record): string
    {
        if ($record->isInEarningsEntryQuarantine()) {
            $tradingDays = EarningsExitSchedule::quarantineTradingDays();

            return "{$record->ticker} zit in de earnings-quarantaine (±{$tradingDays} handelsdagen). De trampoline is fundamenteel onbetrouwbaar.";
        }

        $daysUntil = $record->daysUntilEarnings();
        $daysLabel = $daysUntil === null
            ? 'binnenkort'
            : ($daysUntil === 0 ? 'vandaag' : "over {$daysUntil} dagen");

        return "Earnings {$daysLabel} laten te weinig runway voor een nieuwe swing.";
    }

    /**
     * @param  array<int, string>  $statePaths
     */
    private static function refreshEditRecordForm(mixed $livewire, array $statePaths): void
    {
        if (! is_object($livewire) || ! method_exists($livewire, 'refreshFormData')) {
            return;
        }

        if (method_exists($livewire, 'getRecord')) {
            $record = $livewire->getRecord();

            if ($record instanceof Position) {
                $record->refresh();
            }
        }

        $livewire->refreshFormData($statePaths);
    }
}
