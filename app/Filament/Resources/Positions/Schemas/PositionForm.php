<?php

namespace App\Filament\Resources\Positions\Schemas;

use App\Enums\EarningsReleaseHour;
use App\Enums\PositionVisibility;
use App\Enums\TradeDirection;
use App\Enums\TrailingStopMode;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Models\Position;
use App\Services\SquadContext;
use App\Services\TradingViewSymbolService;
use App\Support\ChartScreenshotUpload;
use App\Support\ClosePriceTrend;
use App\Support\EarningsExitDisplay;
use App\Support\FreerideDisplay;
use App\Support\PositionSizing;
use App\Support\PreBounceExtensionCalculator;
use App\Support\PremarketGatekeeperDisplay;
use App\Support\RelativeVolumeCalculator;
use App\Support\ScaleOutDisplay;
use App\Support\ScoutSetupScorecard;
use App\Support\SlPriceProximity;
use App\Support\StopLossProtocol;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PositionForm
{
    private static bool $scoutFormMode = false;

    public static function configure(Schema $schema, bool $scoutMode = false): Schema
    {
        self::$scoutFormMode = $scoutMode;
        $isScoutForm = fn (?Position $record, string $operation): bool => $scoutMode || $record?->status === 'scout';

        return $schema
            ->components([
                self::cockpitSection(),
                self::scoutCockpitSection(),
                self::scoutCockpitGrid($isScoutForm),
                self::openPositionFormGrid($isScoutForm),
                self::journalKelderSection($isScoutForm),
                self::scoutVisibilitySection($isScoutForm),
            ]);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function scoutCockpitGrid(callable $isScoutForm): Grid
    {
        return Grid::make(['default' => 1, 'lg' => 3])
            ->columnSpanFull()
            ->extraAttributes(['class' => 'scout-cockpit-grid'])
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 2])
                    ->extraAttributes(['class' => 'scout-cockpit-motor'])
                    ->schema([
                        self::scoutSetupValstrikSection($isScoutForm),
                        self::scoutMarktdataSection(),
                        self::scoutSchildInputSection(),
                    ]),
                self::scoutTelemetryColumn(),
            ]);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function openPositionFormGrid(callable $isScoutForm): Grid
    {
        return Grid::make(['default' => 1, 'lg' => 3])
            ->columnSpanFull()
            ->extraAttributes(['class' => 'position-cockpit-grid position-form-columns'])
            ->visible(fn (?Position $record, string $operation): bool => ! $isScoutForm($record, $operation))
            ->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 2])
                    ->extraAttributes(['class' => 'position-cockpit-motor position-form-setup-grid'])
                    ->schema([
                        self::setupDetailsSection($isScoutForm),
                        self::schildSection(),
                        self::earningsOverrideSection(),
                    ]),
                self::openPositionTelemetryColumn(),
            ]);
    }

    private static function openPositionTelemetryColumn(): Grid
    {
        return Grid::make(1)
            ->columnSpan(['lg' => 1])
            ->extraAttributes(['class' => 'position-cockpit-telemetry'])
            ->schema([
                self::orderPlanSection(),
                self::schildStatusSection(),
                self::openPositionJournalSection(),
            ]);
    }

    private static function orderPlanSection(): Section
    {
        return Section::make('Order Plan')
            ->compact()
            ->extraAttributes(['class' => 'vestix-order-plan-section'])
            ->visible(fn (?Position $record): bool => $record?->status === 'open')
            ->schema([
                Placeholder::make('order_plan')
                    ->hiddenLabel()
                    ->content(fn (?Position $record): HtmlString => ScaleOutDisplay::orderPlanHtml($record ?? new Position)),
                Actions::make([
                    PositionRecordActions::scaleOut()->label('Log Scale-out'),
                ])->extraAttributes(['class' => 'vestix-order-plan__step-one-action-host']),
            ]);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function journalKelderSection(callable $isScoutForm): Section
    {
        return self::collapsedTradeJournalSection('position-journal-kelder')
            ->columnSpanFull()
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation));
    }

    private static function openPositionJournalSection(): Section
    {
        return self::collapsedTradeJournalSection('position-journal-sidebar');
    }

    private static function collapsedTradeJournalSection(string $extraClass): Section
    {
        return Section::make('Trade Journal & Notities')
            ->collapsed()
            ->compact()
            ->extraAttributes(['class' => $extraClass.' position-form-journal-section'])
            ->schema(self::tradeJournalFields());
    }

    /**
     * @return array<int, Textarea|mixed>
     */
    private static function tradeJournalFields(): array
    {
        return [
            Textarea::make('trade_journal')
                ->label('Setup & rationale')
                ->hiddenLabel()
                ->rows(4)
                ->maxLength(2000)
                ->placeholder('Gekocht op bounce van 200 EMA, sterke earnings verwacht, sector is bullish.')
                ->extraFieldWrapperAttributes(['class' => 'position-form-journal-field'])
                ->extraInputAttributes(['class' => 'position-form-journal-textarea'])
                ->columnSpanFull(),
            self::chartScreenshotField(
                field: 'entry_chart_screenshot_path',
                label: 'TradingView — entry',
                imageLabel: 'TradingView entry chart',
            ),
            self::chartScreenshotField(
                field: 'exit_chart_screenshot_path',
                label: 'TradingView — exit',
                imageLabel: 'TradingView exit chart',
                visible: fn (?Position $record): bool => $record?->status === 'closed',
            ),
        ];
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function scoutSetupValstrikSection(callable $isScoutForm): Section
    {
        return Section::make('Setup & Valstrik')
            ->description('Vul pas in na een Telegram-alert (fase 3). Low/High = dagkaars (1D) van de bounce-dag in TradingView.')
            ->afterLabel([
                Icon::make('heroicon-o-information-circle')
                    ->tooltip(
                        "Fase 1–2: laat dit blok leeg.\n"
                        ."Fase 3: na Telegram-alert vul je Low/High in van de bounce-dagkaars (TradingView, 1D).\n"
                        ."Buy-Stop: High + 10% × ATR 14 (ATR staat in Het Schild).\n"
                        ."Plaats je buy-stop niet de avond ervoor — zet de reminder aan wanneer je klaar bent; je krijgt de volgende handelsdag een Telegram vlak na market open.\n"
                        .'Zet de Buy-Stop exact zo in je broker — nooit market buy.'
                    )
                    ->color('gray'),
            ])
            ->compact()
            ->divided()
            ->schema([
                self::directionToggleField(),
                Grid::make(3)
                    ->schema([
                        self::tickerField(),
                        self::plannedInvestmentField($isScoutForm),
                        self::scoutAutoComputedTextInput(
                            TextInput::make('quantity')
                            ->label('Gepland aantal')
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('any')
                            ->minValue(0.000001)
                            ->readOnly()
                            ->helperText('Auto-berekend uit totale inleg ÷ entry')
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace(',', '.', $state) : null)
                            ->rules(['nullable', 'numeric', 'min:0.000001'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record))
                            ->afterStateHydrated(function (Set $set, Get $get, ?Position $record): void {
                                self::syncTotalInvestmentField($set, $get, $record);
                                self::hydratePlannedInvestmentField($set, $get, $record);
                            }),
                        ),
                    ]),
                Grid::make(2)
                    ->schema([
                        TextInput::make('entry_price')
                            ->label(fn (Get $get, ?Position $record): string => self::resolveFormDirection($get, $record) === TradeDirection::Short
                                ? 'Geplande entry (Sell-Stop)'
                                : 'Geplande entry')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0.01)
                            ->rules(['nullable', 'numeric', 'min:0.01'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncPlannedQuantityFromInvestment($set, $get, $record))
                            ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncPlannedQuantityFromInvestment($set, $get, $record)),
                        self::marketOpenReminderToggle()
                            ->visible(fn (?Position $record, string $operation): bool => $operation === 'edit'),
                    ]),
                self::bankrollSetupCallout($isScoutForm),
                self::earningsSmartAlert(),
                self::shortEarningsWarningCallout(),
                Grid::make(3)
                    ->schema(self::buyStopFields()),
            ]);
    }

    private static function directionToggleField(): ToggleButtons
    {
        return ToggleButtons::make('direction')
            ->label('Richting')
            ->options([
                TradeDirection::Long->value => 'LONG',
                TradeDirection::Short->value => 'SHORT',
            ])
            ->default(TradeDirection::Long->value)
            ->inline()
            ->inlineLabel()
            ->live()
            ->visible(fn (): bool => (bool) auth()->user()?->canUseShort())
            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncEntryStopFromSignal($set, $get, $record))
            ->dehydrated(fn (): bool => true)
            ->extraFieldWrapperAttributes(['class' => 'vestix-direction-field'])
            ->extraAttributes(['class' => 'vestix-direction-segment'])
            ->columnSpanFull();
    }

    private static function shortEarningsWarningCallout(): Callout
    {
        return Callout::make('LET OP: SHORT binnen 14 dagen van earnings')
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->description('Short nooit een aandeel dat binnen 14 dagen met kwartaalcijfers komt. Een positieve verrassing is dodelijk — opslaan mag, maar heroverweeg deze setup.')
            ->visible(function (Get $get, ?Position $record): bool {
                if (self::resolveFormDirection($get, $record) !== TradeDirection::Short) {
                    return false;
                }

                if ($record === null) {
                    return false;
                }

                $record->loadMissing('asset');

                return EarningsExitDisplay::isWithinAlertWindow($record);
            })
            ->columnSpanFull();
    }

    private static function scoutMarktdataSection(): Section
    {
        return Section::make('Marktdata & Indicatoren')
            ->description(fn (string $operation): string => $operation === 'create'
                ? 'Vul handmatig in — na opslaan kun je rechtsboven "Data ophalen" gebruiken'
                : 'Klik "Data ophalen" rechtsboven, of vul handmatig in')
            ->compact()
            ->schema([
                Grid::make(2)
                    ->schema(self::scoutMarktdataFields()),
            ]);
    }

    private static function scoutSchildInputSection(): Section
    {
        return Section::make('Het Schild')
            ->compact()
            ->divided()
            ->schema([
                Grid::make(3)
                    ->schema(self::scoutSchildInputFields()),
            ]);
    }

    private static function scoutTelemetryColumn(): Grid
    {
        return Grid::make(1)
            ->columnSpan(['lg' => 1])
            ->extraAttributes(['class' => 'scout-cockpit-telemetry'])
            ->schema([
                self::scoutValidationTelemetrySection(),
                self::scoutScorecardTelemetrySection(),
            ]);
    }

    private static function scoutValidationTelemetrySection(): Section
    {
        return Section::make('Wiskundige Validatie')
            ->compact()
            ->schema([
                View::make('filament.positions.trampoline-validation-matrix')
                    ->viewData(function (Get $get, ?Position $record): array {
                        return self::validationMatrixData($get, $record);
                    })
                    ->columnSpanFull(),
            ]);
    }

    private static function scoutScorecardTelemetrySection(): Section
    {
        return Section::make('Sniper Scorecard')
            ->description('Objectieve setup-beoordeling (max '.ScoutSetupScorecard::maxPoints().' punten)')
            ->icon('heroicon-m-viewfinder-circle')
            ->compact()
            ->schema([
                View::make('filament.positions.scout-scorecard-hud')
                    ->viewData(function (Get $get, ?Position $record): array {
                        $score = self::resolveScorecard($get, $record);

                        return [
                            'score' => $score,
                            'hudTone' => self::scorecardHudTone($score),
                            'cardVariant' => self::scorecardCardVariant($score),
                        ];
                    }),
                Callout::make('Setup geblokkeerd')
                    ->visible(fn (Get $get, ?Position $record): bool => self::resolveScorecard($get, $record)['hardFailReasons'] !== [])
                    ->description(fn (Get $get, ?Position $record): string => implode("\n", self::resolveScorecard($get, $record)['hardFailReasons']))
                    ->danger()
                    ->icon('heroicon-o-exclamation-triangle'),
                Grid::make(1)
                    ->extraAttributes(['class' => 'scout-scorecard-criteria'])
                    ->schema([
                        self::scorecardCriterionEntry('trampoline'),
                        self::scorecardCriterionEntry('sma_direction'),
                        self::scorecardCriterionEntry('rsi'),
                        self::scorecardCriterionEntry('volume'),
                        self::scorecardCriterionEntry('sector'),
                        self::scorecardCriterionEntry('extension'),
                    ]),
            ]);
    }

    /**
     * @return array<int, TextInput|Toggle>
     */
    private static function scoutMarktdataFields(): array
    {
        return [
            TextInput::make('scout_rsi')
                ->label('RSI 14')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->helperText('Auto ingevuld bij Data ophalen — handmatig overschrijfbaar')
                ->live(debounce: 300),
            TextInput::make('sma_20_five_days_ago')
                ->label('SMA 20 (5 dagen geleden)')
                ->numeric()
                ->prefix('$')
                ->live(debounce: 300),
            TextInput::make('sma_20_ten_days_ago')
                ->label(sprintf('SMA 20 (%d dagen geleden)', ScoutSetupScorecard::smaSlopeLookbackDays()))
                ->numeric()
                ->prefix('$')
                ->helperText('Gebruikt voor SMA-helling in scorecard')
                ->live(debounce: 300),
            TextInput::make('latest_sma_50')
                ->label('SMA 50')
                ->numeric()
                ->prefix('$')
                ->live(debounce: 300),
            TextInput::make('latest_sma_20')
                ->label('SMA 20')
                ->numeric()
                ->prefix('$')
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncPlannedQuantityFromInvestment($set, $get, $record)),
            Toggle::make('bounce_volume_above_average')
                ->label('RVol bevestigd (≥ '.RelativeVolumeCalculator::formatThresholdPercent().')')
                ->disabled()
                ->dehydrated()
                ->helperText('Automatisch bij Data ophalen op bounce-dag')
                ->extraFieldWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes()),
            Placeholder::make('relative_volume_display')
                ->label('Relative Volume (RVol)')
                ->content(fn (Get $get, ?Position $record): string => RelativeVolumeCalculator::formatPercent(
                    $get('relative_volume') ?? $record?->relative_volume,
                ) ?? '—')
                ->visible(fn (Get $get, ?Position $record): bool => filled($get('relative_volume') ?? $record?->relative_volume))
                ->extraEntryWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes()),
            Placeholder::make('volume_sma_20_display')
                ->label('Volume SMA 20')
                ->content(fn (Get $get, ?Position $record): string => number_format(
                    (int) ($get('volume_sma_20') ?? $record?->volume_sma_20 ?? 0),
                    0,
                    ',',
                    '.',
                ))
                ->visible(fn (Get $get, ?Position $record): bool => filled($get('volume_sma_20') ?? $record?->volume_sma_20))
                ->extraEntryWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes()),
            Placeholder::make('bounce_day_volume_display')
                ->label('Volume bounce-dag')
                ->content(fn (Get $get, ?Position $record): string => number_format(
                    (int) ($get('bounce_day_volume') ?? $record?->bounce_day_volume ?? 0),
                    0,
                    ',',
                    '.',
                ))
                ->visible(fn (Get $get, ?Position $record): bool => filled($get('bounce_day_volume') ?? $record?->bounce_day_volume))
                ->extraEntryWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes()),
            Select::make('sector_etf_override')
                ->label('Sector ETF')
                ->options(fn (): array => self::sectorEtfOverrideSelectOptions())
                ->nullable()
                ->placeholder('Auto via Finnhub')
                ->native(false)
                ->searchable()
                ->helperText('Laat leeg voor auto-detectie via Finnhub, of kies handmatig een sector-ETF')
                ->live(debounce: 300),
            Placeholder::make('sector_etf_display')
                ->label('Sector ETF (gedetecteerd)')
                ->content(fn (Get $get, ?Position $record): string => strtoupper((string) ($get('sector_etf') ?? $record?->sector_etf ?? '')))
                ->visible(fn (Get $get, ?Position $record): bool => filled($get('sector_etf') ?? $record?->sector_etf))
                ->extraEntryWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes()),
            Placeholder::make('pre_bounce_extension_display')
                ->label('Pre-bounce extensie (ATR)')
                ->content(fn (Get $get, ?Position $record): string => number_format(
                    (float) ($get('pre_bounce_extension_atr') ?? $record?->pre_bounce_extension_atr ?? 0),
                    2,
                    ',',
                    '.',
                ))
                ->visible(fn (Get $get, ?Position $record): bool => filled($get('pre_bounce_extension_atr') ?? $record?->pre_bounce_extension_atr))
                ->extraEntryWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes()),
        ];
    }

    /**
     * @return array{class: string}
     */
    private static function scoutTelemetryReadonlyWrapperAttributes(): array
    {
        return ['class' => 'scout-telemetry-readonly'];
    }

    /**
     * @return array<int, TextInput>
     */
    private static function scoutSchildInputFields(): array
    {
        return [
            TextInput::make('latest_open_price')
                ->label('Open prijs')
                ->numeric()
                ->prefix('$')
                ->live(),
            TextInput::make('latest_close_price')
                ->label('Close prijs')
                ->numeric()
                ->prefix('$')
                ->live(),
            TextInput::make('latest_atr_14')
                ->label('ATR 14')
                ->numeric()
                ->prefix('$')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?Position $record): void {
                    self::syncPlannedQuantityFromInvestment($set, $get, $record);

                    if (blank($get('signal_high')) && blank($record?->signal_high)) {
                        return;
                    }

                    self::syncBuyStopFromInputs($set, $get, $record);
                }),
        ];
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function scoutVisibilitySection(callable $isScoutForm): Section
    {
        return Section::make('Zichtbaarheid')
            ->compact()
            ->columnSpanFull()
            ->description('Privé (Ghost Mode): alleen jij ziet deze setup. Squad: zichtbaar voor je teamgenoten.')
            ->extraAttributes(['class' => 'scout-visibility-section'])
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation)
                && (($user = auth()->user()) !== null
                    && app(SquadContext::class)->userCanInAnySquad($user, 'scout.share')))
            ->afterHeader([
                self::scoutVisibilityToggle(),
            ])
            ->schema([
                Hidden::make('visibility')
                    ->default(PositionVisibility::Private->value),
                Select::make('squad_id')
                    ->label('Squad')
                    ->options(function (): array {
                        $user = auth()->user();

                        if ($user === null) {
                            return [];
                        }

                        return app(SquadContext::class)
                            ->squadsWhereUserCan($user, 'scout.share')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->visible(fn (Get $get): bool => self::visibilityIsSquad($get('visibility')))
                    ->native(false)
                    ->columnSpanFull(),
            ]);
    }

    private static function visibilityIsSquad(mixed $state): bool
    {
        if ($state instanceof PositionVisibility) {
            return $state === PositionVisibility::Squad;
        }

        if ($state === true) {
            return true;
        }

        return PositionVisibility::tryFrom((string) $state) === PositionVisibility::Squad;
    }

    private static function scoutVisibilityToggle(): Toggle
    {
        return Toggle::make('share_with_squad')
            ->label('Deel met squad')
            ->inline(false)
            ->onIcon('heroicon-m-user-group')
            ->offIcon('heroicon-m-eye-slash')
            ->onColor('success')
            ->offColor('gray')
            ->dehydrated(false)
            ->afterStateHydrated(function (Toggle $component, $state, Get $get, ?Position $record): void {
                $component->state(self::visibilityIsSquad($get('visibility') ?? $record?->visibility));
            })
            ->live()
            ->afterStateUpdated(function (bool $state, Set $set): void {
                $set('visibility', $state
                    ? PositionVisibility::Squad->value
                    : PositionVisibility::Private->value);

                if (! $state) {
                    $set('squad_id', null);

                    return;
                }

                $user = auth()->user();

                if ($user === null) {
                    return;
                }

                $squads = app(SquadContext::class)->squadsWhereUserCan($user, 'scout.share');

                if ($squads->count() === 1) {
                    $set('squad_id', $squads->first()?->id);
                }
            });
    }

    private static function marketOpenReminderToggle(): Toggle
    {
        return Toggle::make('market_open_reminder_enabled')
            ->label('Herinner bij market open')
            ->inline(false)
            ->onIcon('heroicon-m-bell-alert')
            ->offIcon('heroicon-m-bell-slash')
            ->onColor('info')
            ->offColor('gray')
            ->dehydrated(false)
            ->afterStateHydrated(function (Toggle $component, ?Position $record): void {
                $component->state($record?->market_open_reminder_on !== null);
            })
            ->live()
            ->afterStateUpdated(function (bool $state, ?Position $record): void {
                if (! $record instanceof Position) {
                    return;
                }

                if ($state) {
                    $record->scheduleMarketOpenReminder();
                } else {
                    $record->clearMarketOpenReminder();
                }

                $record->refresh();
            })
            ->disabled(fn (Get $get): bool => blank($get('entry_price')))
            ->helperText(function (Get $get, ?Position $record): string {
                if (blank($get('entry_price'))) {
                    return 'Vul eerst Low/High in zodat de buy-stop berekend is.';
                }

                $record?->refresh();
                $reminderOn = $record?->market_open_reminder_on;

                if ($reminderOn !== null) {
                    return sprintf(
                        'Reminder gepland op %s om %s — Telegram met buy-stop en broker-link.',
                        $reminderOn->format('d-m-Y'),
                        config('vestix.market_open_reminder.time', '15:35'),
                    );
                }

                return sprintf(
                    'Aan: volgende handelsdag om %s Telegram-reminder. Plaats je order pas ná market open.',
                    config('vestix.market_open_reminder.time', '15:35'),
                );
            });
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function setupDetailsSection(callable $isScoutForm): Section
    {
        return Section::make()
            ->heading(fn (?Position $record, string $operation): ?string => $isScoutForm($record, $operation) ? 'Setup' : null)
            ->compact()
            ->divided()
            ->schema([
                Grid::make(3)
                    ->schema([
                        self::tickerField(),
                        self::plannedInvestmentField($isScoutForm),
                        TextInput::make('quantity')
                            ->label(function (?Position $record, string $operation) use ($isScoutForm): string {
                                return $isScoutForm($record, $operation) ? 'Gepland aantal' : 'Aantal';
                            })
                            ->required(function (?Position $record, string $operation) use ($isScoutForm): bool {
                                return ! $isScoutForm($record, $operation);
                            })
                            ->numeric()
                            ->inputMode('decimal')
                            ->step('any')
                            ->minValue(0.000001)
                            ->readOnly(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
                            ->extraFieldWrapperAttributes(fn (?Position $record, string $operation): array => $isScoutForm($record, $operation)
                                ? self::scoutTelemetryReadonlyWrapperAttributes()
                                : [])
                            ->extraInputAttributes(fn (?Position $record, string $operation): array => $isScoutForm($record, $operation)
                                ? ['class' => 'scout-readonly-computed-input', 'tabindex' => '-1']
                                : [])
                            ->helperText(fn (?Position $record, string $operation): ?string => $isScoutForm($record, $operation)
                                ? 'Auto-berekend uit totale inleg ÷ entry'
                                : null)
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace(',', '.', $state) : null)
                            ->rules(function (?Position $record, string $operation) use ($isScoutForm): array {
                                return $isScoutForm($record, $operation)
                                    ? ['nullable', 'numeric', 'min:0.000001']
                                    : ['required', 'numeric', 'min:0.000001'];
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record))
                            ->afterStateHydrated(function (Set $set, Get $get, ?Position $record, string $operation) use ($isScoutForm): void {
                                self::syncTotalInvestmentField($set, $get, $record);

                                if ($isScoutForm($record, $operation)) {
                                    self::hydratePlannedInvestmentField($set, $get, $record);
                                }
                            }),
                        self::totalInvestmentField($isScoutForm),
                    ]),
                Grid::make(2)
                    ->schema([
                        TextInput::make('entry_price')
                            ->label(function (?Position $record, string $operation) use ($isScoutForm): string {
                                return $isScoutForm($record, $operation) ? 'Geplande entry' : 'Entry prijs';
                            })
                            ->required(function (?Position $record, string $operation) use ($isScoutForm): bool {
                                return ! $isScoutForm($record, $operation);
                            })
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0.01)
                            ->rules(function (?Position $record, string $operation) use ($isScoutForm): array {
                                return $isScoutForm($record, $operation)
                                    ? ['nullable', 'numeric', 'min:0.01']
                                    : ['required', 'numeric', 'min:0.01'];
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, ?Position $record, string $operation) use ($isScoutForm): void {
                                self::syncTotalInvestmentField($set, $get, $record);

                                if ($isScoutForm($record, $operation)) {
                                    self::syncPlannedQuantityFromInvestment($set, $get, $record);
                                }
                            })
                            ->afterStateHydrated(function (Set $set, Get $get, ?Position $record, string $operation) use ($isScoutForm): void {
                                self::syncTotalInvestmentField($set, $get, $record);

                                if ($isScoutForm($record, $operation)) {
                                    self::syncPlannedQuantityFromInvestment($set, $get, $record);
                                }
                            }),
                        self::marketOpenReminderToggle()
                            ->visible(function (?Position $record, string $operation) use ($isScoutForm): bool {
                                return $operation === 'edit' && $isScoutForm($record, $operation);
                            }),
                        TextInput::make('current_sl')
                            ->label('Huidige stop-loss')
                            ->hint(fn (Get $get, ?Position $record): ?string => self::currentSlStatusLabel($get, $record))
                            ->hintIcon(fn (Get $get, ?Position $record): ?string => self::currentSlStatusIcon($get, $record))
                            ->hintIconTooltip(fn (Get $get, ?Position $record): ?string => self::currentSlStatusTooltip($get, $record))
                            ->hintColor(fn (Get $get, ?Position $record): string => self::currentSlStatusColor($get, $record))
                            ->required(function (?Position $record, string $operation) use ($isScoutForm): bool {
                                return ! $isScoutForm($record, $operation);
                            })
                            ->visible(function (?Position $record, string $operation) use ($isScoutForm): bool {
                                return ! $isScoutForm($record, $operation);
                            })
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0.01)
                            ->live(onBlur: true),
                    ]),
                self::bankrollSetupCallout($isScoutForm),
                self::earningsSmartAlert(),
            ]);
    }

    private static function earningsSmartAlert(): View
    {
        return View::make('filament.positions.earnings-smart-alert')
            ->visible(fn (?Position $record, string $operation): bool => EarningsExitDisplay::isSmartAlertVisible($record, $operation)
                || EarningsExitDisplay::isScoutEntryAlertVisible($record, $operation))
            ->viewData(fn (?Position $record): array => $record instanceof Position
                ? match (true) {
                    $record->status === 'scout' => EarningsExitDisplay::scoutEntryAlertViewData($record),
                    default => EarningsExitDisplay::smartAlertViewData($record),
                }
                : [])
            ->columnSpanFull();
    }

    private static function earningsOverrideSection(): Section
    {
        return Section::make('Earnings Data (API Override)')
            ->description('Vestix haalt deze data automatisch op. Vul dit alleen in als de API sync faalt.')
            ->icon('heroicon-o-calendar')
            ->afterHeader([
                Text::make(fn (?Position $record): ?string => EarningsExitDisplay::sectionDaysBadgeLabel($record))
                    ->badge()
                    ->color(fn (?Position $record): string => EarningsExitDisplay::sectionDaysBadgeColor($record))
                    ->visible(fn (?Position $record): bool => EarningsExitDisplay::sectionDaysBadgeLabel($record) !== null),
            ])
            ->collapsed()
            ->compact()
            ->extraAttributes(['class' => 'position-earnings-section'])
            ->visible(fn (?Position $record, string $operation): bool => $operation === 'edit'
                && $record?->status === 'open')
            ->schema([
                Placeholder::make('earnings_sync_status')
                    ->hiddenLabel()
                    ->content(fn (?Position $record): HtmlString => EarningsExitDisplay::syncStatusContent($record))
                    ->columnSpanFull(),
                DatePicker::make('asset_earnings_date_override')
                    ->label('Earnings datum (override)')
                    ->native(false)
                    ->placeholder(fn (?Position $record): string => $record?->asset?->next_earnings_date
                        ? 'Automatisch: '.$record->asset->next_earnings_date->format('d-m-Y')
                        : 'Geen automatische datum'),
                Select::make('asset_earnings_hour_override')
                    ->label('Publicatietijd (override)')
                    ->native(false)
                    ->options([
                        '' => 'Automatisch',
                        EarningsReleaseHour::Bmo->value => 'Before Market Open',
                        EarningsReleaseHour::Amc->value => 'After Market Close',
                    ]),
            ])
            ->columns(2)
            ->columnSpanFull();
    }

    private static function schildSection(): Section
    {
        return Section::make('Schild')
            ->compact()
            ->divided()
            ->visible(fn (?Position $record): bool => $record?->status !== 'closed')
            ->schema([
                Grid::make(3)
                    ->schema(self::schildMarketDataFields())
                    ->columnSpanFull(),
            ]);
    }

    private static function schildStatusSection(): Section
    {
        return Section::make('Schild status')
            ->compact()
            ->extraAttributes(['class' => 'vestix-schild-status-section'])
            ->visible(fn (?Position $record): bool => $record?->status === 'open')
            ->schema([
                Grid::make(1)
                    ->extraAttributes(['class' => 'vestix-schild-status-grid'])
                    ->schema(self::schildStatusFields())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, Placeholder>
     */
    private static function schildStatusFields(): array
    {
        return [
            Placeholder::make('rsi_telemetry')
                ->label('RSI (14)')
                ->content(fn (Get $get, ?Position $record): HtmlString => new HtmlString(
                    view('filament.positions.rsi-telemetry', [
                        'rsi' => $get('scout_rsi') ?? $record?->scout_rsi,
                        'threshold' => StopLossProtocol::rsiThreshold(),
                    ])->render(),
                )),
            Placeholder::make('atr_telemetry')
                ->label('ATR 14')
                ->content(fn (Get $get, ?Position $record): HtmlString => new HtmlString(
                    view('filament.positions.atr-telemetry', [
                        'atr' => $get('latest_atr_14') ?? $record?->latest_atr_14,
                    ])->render(),
                )),
            Placeholder::make('sma_extension_pct')
                ->label('Extensie SMA20')
                ->content(fn (Get $get, ?Position $record): HtmlString => new HtmlString(
                    view('filament.positions.sma-extension-telemetry', [
                        'extension' => StopLossProtocol::computeSmaExtensionPct(
                            $get('latest_close_price') ?? $record?->latest_close_price,
                            $get('latest_sma_20') ?? $record?->latest_sma_20,
                        ),
                    ])->render(),
                )),
        ];
    }

    /**
     * @return array<int, TextInput>
     */
    private static function schildMarketDataFields(): array
    {
        return [
            TextInput::make('latest_open_price')
                ->label('Open prijs')
                ->numeric()
                ->prefix('$')
                ->live(),
            TextInput::make('latest_close_price')
                ->label('Close prijs')
                ->numeric()
                ->prefix('$')
                ->live(),
            TextInput::make('latest_sma_20')
                ->label('SMA 20')
                ->numeric()
                ->prefix('$')
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncPlannedQuantityFromInvestment($set, $get, $record)),
            TextInput::make('latest_atr_14')
                ->label('ATR 14')
                ->numeric()
                ->prefix('$')
                ->live(onBlur: true)
                ->visible(fn (?Position $record): bool => $record?->status !== 'open')
                ->afterStateUpdated(function (Set $set, Get $get, ?Position $record): void {
                    self::syncPlannedQuantityFromInvestment($set, $get, $record);

                    if (blank($get('signal_high')) && blank($record?->signal_high)) {
                        return;
                    }

                    self::syncBuyStopFromInputs($set, $get, $record);
                }),
        ];
    }

    private static function currentSlStatusLabel(Get $get, ?Position $record): ?string
    {
        $position = self::resolvePositionForProtocol($get, $record);

        if ($position === null) {
            return null;
        }

        return match (StopLossProtocol::activeMode($position)) {
            TrailingStopMode::AggressivePreEarnings => 'Escalatie',
            TrailingStopMode::AggressiveOverbought => 'Agressief',
            default => null,
        };
    }

    private static function currentSlStatusIcon(Get $get, ?Position $record): ?string
    {
        $position = self::resolvePositionForProtocol($get, $record);

        if ($position === null) {
            return null;
        }

        return match (StopLossProtocol::activeMode($position)) {
            TrailingStopMode::Standard => null,
            default => 'heroicon-m-bolt',
        };
    }

    private static function currentSlStatusColor(Get $get, ?Position $record): string
    {
        $position = self::resolvePositionForProtocol($get, $record);

        if ($position === null) {
            return 'gray';
        }

        return match (StopLossProtocol::activeMode($position)) {
            TrailingStopMode::AggressivePreEarnings => 'danger',
            TrailingStopMode::AggressiveOverbought => 'warning',
            default => 'gray',
        };
    }

    private static function currentSlStatusTooltip(Get $get, ?Position $record): ?string
    {
        $position = self::resolvePositionForProtocol($get, $record);

        if ($position === null) {
            return null;
        }

        $mode = StopLossProtocol::activeMode($position);

        if ($mode === TrailingStopMode::Standard) {
            return null;
        }

        $newSl = self::resolveComputedNewSl($get, $record);
        $action = self::resolveFormActionCommand($get, $record);

        $formula = match ($mode) {
            TrailingStopMode::AggressivePreEarnings => StopLossProtocol::aggressiveFormulaLabel(),
            default => StopLossProtocol::overboughtFormulaLabel(),
        };

        $context = match ($mode) {
            TrailingStopMode::AggressivePreEarnings => 'Pre-earnings escalatie actief',
            default => 'RSI oververhit — agressieve ATR-trailing actief',
        };

        if ($action === 'UPDATE' && $newSl !== null) {
            return sprintf(
                "Systeem adviseert %s.\n%s (%s).",
                self::formatUsd($newSl),
                $context,
                $formula,
            );
        }

        return sprintf('%s (%s).', $context, $formula);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function buyStopSection(callable $isScoutForm): Section
    {
        return Section::make('Executie & Valstrik')
            ->description('Vul pas in na een Telegram-alert (fase 3). Low/High = dagkaars (1D) van de bounce-dag in TradingView.')
            ->afterLabel([
                Icon::make('heroicon-o-information-circle')
                    ->tooltip(
                        "Fase 1–2: laat dit blok leeg.\n"
                        ."Fase 3: na Telegram-alert vul je Low/High in van de bounce-dagkaars (TradingView, 1D).\n"
                        ."Buy-Stop: High + 10% × ATR 14 (ATR staat in Setup).\n"
                        ."Plaats je buy-stop niet de avond ervoor — zet de reminder aan wanneer je klaar bent; je krijgt de volgende handelsdag een Telegram vlak na market open.\n"
                        .'Zet de Buy-Stop exact zo in je broker — nooit market buy.'
                    )
                    ->color('gray'),
            ])
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->compact()
            ->columns(3)
            ->schema(self::buyStopFields());
    }

    /**
     * @return array<int, TextInput|Placeholder>
     */
    private static function buyStopFields(): array
    {
        return [
            TextInput::make('signal_low')
                ->label('Low (Signaalkaars)')
                ->numeric()
                ->prefix('$')
                ->minValue(0.01)
                ->rules(['nullable', 'numeric', 'min:0.01'])
                ->helperText(fn (Get $get, ?Position $record): string => self::resolveFormDirection($get, $record) === TradeDirection::Short
                    ? 'Laagste punt van de rode afwijzingskaars (TradingView, 1D). Drijft de Sell-Stop.'
                    : 'Optioneel tot bounce-dag. Laagste punt van de bounce-dagkaars (TradingView, 1D).')
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncEntryStopFromSignal($set, $get, $record))
                ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncEntryStopFromSignal($set, $get, $record)),
            TextInput::make('signal_high')
                ->label('High (Signaalkaars)')
                ->numeric()
                ->prefix('$')
                ->minValue(0.01)
                ->rules(['nullable', 'numeric', 'min:0.01'])
                ->helperText(fn (Get $get, ?Position $record): string => self::resolveFormDirection($get, $record) === TradeDirection::Short
                    ? 'Hoogste punt / plafond van de afwijzingskaars (TradingView, 1D).'
                    : 'Optioneel tot bounce-dag. Hoogste punt van de bounce-dagkaars (TradingView, 1D).')
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncEntryStopFromSignal($set, $get, $record))
                ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncEntryStopFromSignal($set, $get, $record)),
            Placeholder::make('advised_entry_display')
                ->label(fn (Get $get, ?Position $record): string => self::resolveFormDirection($get, $record) === TradeDirection::Short
                    ? 'Geadviseerde Sell-Stop'
                    : 'Geadviseerde Buy-Stop')
                ->content(fn (Get $get, ?Position $record): string => self::formatAdvisedEntryStopDisplay($get, $record))
                ->extraEntryWrapperAttributes(fn (Get $get, ?Position $record): array => [
                    'class' => 'scout-telemetry-readonly scout-advised-buy-stop'.(self::advisedEntryStopPending($get, $record)
                        ? ' scout-advised-buy-stop--pending'
                        : ''),
                ]),
        ];
    }

    private static function advisedEntryStopPending(Get $get, ?Position $record): bool
    {
        if (self::resolveFormDirection($get, $record) === TradeDirection::Short) {
            return blank($get('signal_low') ?? $record?->signal_low);
        }

        return blank($get('signal_high') ?? $record?->signal_high);
    }

    private static function formatAdvisedEntryStopDisplay(Get $get, ?Position $record): string
    {
        $direction = self::resolveFormDirection($get, $record);

        if ($direction === TradeDirection::Short) {
            if (blank($get('signal_low') ?? $record?->signal_low)) {
                return 'Vul Low in om Sell-Stop te berekenen';
            }

            $sellStop = Position::computeSellStop(
                $get('signal_low') ?? $record?->signal_low,
                $get('latest_atr_14') ?? $record?->latest_atr_14,
            );

            if ($sellStop === null) {
                return '—';
            }

            return '$'.number_format($sellStop, 2, ',', '.');
        }

        if (blank($get('signal_high') ?? $record?->signal_high)) {
            return 'Vul High in om Buy-Stop te berekenen';
        }

        $buyStop = Position::computeBuyStop(
            $get('signal_high') ?? $record?->signal_high,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
        );

        if ($buyStop === null) {
            return '—';
        }

        return '$'.number_format($buyStop, 2, ',', '.');
    }

    /** @deprecated Use formatAdvisedEntryStopDisplay() */
    private static function formatAdvisedBuyStopDisplay(Get $get, ?Position $record): string
    {
        return self::formatAdvisedEntryStopDisplay($get, $record);
    }

    private static function scoutAutoComputedTextInput(TextInput $field): TextInput
    {
        return $field
            ->extraFieldWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes())
            ->extraInputAttributes([
                'class' => 'scout-readonly-computed-input',
                'tabindex' => '-1',
            ]);
    }

    private static function scoutAutoComputedPlaceholder(Placeholder $field): Placeholder
    {
        return $field->extraEntryWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes());
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function trampolineValidationSection(callable $isScoutForm): Section
    {
        return Section::make('Wiskundige Validatie')
            ->description('Voldoet deze setup aan de keiharde eisen van de Trampoline Bounce strategie?')
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->compact()
            ->schema([
                View::make('filament.positions.trampoline-validation-matrix')
                    ->viewData(function (Get $get, ?Position $record): array {
                        return self::validationMatrixData($get, $record);
                    })
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array{
     *     volume: array{label: string, tone: string},
     *     sector: array{label: string, tone: string},
     *     extension: array{label: string, tone: string},
     * }
     */
    private static function validationMatrixData(Get $get, ?Position $record): array
    {
        $rvol = $get('relative_volume') ?? $record?->relative_volume;
        $rvolThreshold = RelativeVolumeCalculator::rvolThreshold();
        $rvolFloat = RelativeVolumeCalculator::normalizeRatio($rvol);

        $volumeLabel = $rvolFloat === null
            ? 'RVol: — (wacht op data)'
            : sprintf('RVol: %s (%s)', RelativeVolumeCalculator::formatPercent($rvolFloat), match (true) {
                $rvolFloat >= $rvolThreshold => 'Geldstroom actief',
                $rvolFloat < 1.0 => 'Zwak',
                default => 'Matig',
            });

        $volumeTone = match (true) {
            $rvolFloat === null => 'muted',
            $rvolFloat >= $rvolThreshold => 'success',
            $rvolFloat < 1.0 => 'muted',
            default => 'warning',
        };

        $etf = strtoupper((string) ($get('sector_etf') ?? $record?->sector_etf ?? ''));
        $sectorPositive = (bool) ($get('sector_trend_positive') ?? $record?->sector_trend_positive);

        $sectorLabel = $etf === ''
            ? 'Sector: — (wacht op data)'
            : (self::resolveFormDirection($get, $record) === TradeDirection::Short
                ? ($sectorPositive
                    ? "Sector ({$etf}): Meewind"
                    : "Sector ({$etf}): Tegenwind")
                : ($sectorPositive
                    ? "Sector ({$etf}): Trend Positief"
                    : "Sector ({$etf}): Tegenwind"));

        $sectorTone = $etf === ''
            ? 'muted'
            : match (self::resolveFormDirection($get, $record) === TradeDirection::Short) {
                true => $sectorPositive ? 'danger' : 'success',
                false => $sectorPositive ? 'success' : 'danger',
            };

        $extension = $get('pre_bounce_extension_atr') ?? $record?->pre_bounce_extension_atr;
        $extensionThreshold = PreBounceExtensionCalculator::extensionThreshold();
        $extensionFloat = $extension !== null && $extension !== '' ? (float) $extension : null;

        $extensionLabel = $extensionFloat === null
            ? 'Pre-bounce extensie: — (wacht op data)'
            : sprintf(
                'Pre-bounce extensie: +%.1f ATR (%s)',
                $extensionFloat,
                $extensionFloat >= $extensionThreshold ? 'Hoge spanning' : 'Lage spanning',
            );

        $extensionTone = match (true) {
            $extensionFloat === null => 'muted',
            $extensionFloat >= $extensionThreshold => 'success',
            default => 'danger',
        };

        return [
            'volume' => ['label' => $volumeLabel, 'tone' => $volumeTone],
            'sector' => ['label' => $sectorLabel, 'tone' => $sectorTone],
            'extension' => ['label' => $extensionLabel, 'tone' => $extensionTone],
        ];
    }

    private static function tickerField(): Select
    {
        return Select::make('ticker')
            ->label('Ticker')
            ->required()
            ->searchable()
            ->searchPrompt('Zoek op ticker of bedrijfsnaam…')
            ->searchDebounce(400)
            ->getSearchResultsUsing(fn (string $search): array => app(TradingViewSymbolService::class)->searchForForm($search))
            ->getOptionLabelUsing(fn (?string $value): ?string => blank($value) ? null : strtoupper($value))
            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null)
            // ->helperText('Zoek en kies de juiste listing — voorkomt typefouten in de ticker.')
            ->live(onBlur: true);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function plannedInvestmentField(callable $isScoutForm): TextInput
    {
        return TextInput::make('_planned_investment')
            ->label('Totale inleg')
            ->prefix('$')
            ->numeric()
            ->minValue(0.01)
            ->dehydrated(false)
            ->placeholder('bijv. 1000')
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->helperText(fn (Get $get, ?Position $record): ?string => self::plannedInvestmentHelperText($get, $record))
            ->live()
            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncPlannedQuantityFromInvestment($set, $get, $record))
            ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::hydratePlannedInvestmentField($set, $get, $record));
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function bankrollSetupCallout(callable $isScoutForm): Callout
    {
        return Callout::make('Bankroll instellen')
            ->info()
            ->icon('heroicon-o-banknotes')
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation)
                && ! self::userHasBankroll())
            ->description('Stel je bankroll in via Profiel → Position sizing om je positiegrootte te berekenen.')
            ->columnSpanFull();
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function totalInvestmentField(callable $isScoutForm): TextInput
    {
        return TextInput::make('_total_investment')
            ->label('Totale inleg')
            ->prefix('$')
            ->readOnly()
            ->dehydrated(false)
            ->placeholder('—')
            ->visible(fn (?Position $record, string $operation): bool => ! $isScoutForm($record, $operation))
            ->extraFieldWrapperAttributes(self::scoutTelemetryReadonlyWrapperAttributes())
            ->extraInputAttributes([
                'class' => 'scout-readonly-computed-input',
                'tabindex' => '-1',
            ])
            ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record));
    }

    private static function syncTotalInvestmentField(Set $set, Get $get, ?Position $record): void
    {
        $entry = $get('entry_price') ?? $record?->entry_price;
        $quantity = $get('quantity') ?? $record?->quantity;

        if ($entry === null || $entry === '' || $quantity === null || $quantity === '') {
            $set('_total_investment', null);

            return;
        }

        $set('_total_investment', number_format((float) $entry * (float) $quantity, 2, '.', ''));
    }

    private static function syncPlannedQuantityFromInvestment(Set $set, Get $get, ?Position $record): void
    {
        if (! self::isScoutSizingContext($record)) {
            return;
        }

        $investment = $get('_planned_investment');
        $entry = $get('entry_price') ?? $record?->entry_price;

        if (blank($investment) || $entry === null || $entry === '') {
            return;
        }

        $quantity = PositionSizing::quantityFromInvestment((float) $investment, (float) $entry);

        if ($quantity === null || $quantity < 1) {
            $set('quantity', null);

            return;
        }

        $set('quantity', (string) $quantity);
    }

    private static function hydratePlannedInvestmentField(Set $set, Get $get, ?Position $record): void
    {
        if (! self::isScoutSizingContext($record)) {
            return;
        }

        if (filled($get('_planned_investment'))) {
            self::syncPlannedQuantityFromInvestment($set, $get, $record);

            return;
        }

        $entry = $get('entry_price') ?? $record?->entry_price;
        $quantity = $get('quantity') ?? $record?->quantity;

        if ($entry !== null && $entry !== '' && $quantity !== null && $quantity !== '') {
            $set('_planned_investment', number_format((float) $entry * (float) $quantity, 2, '.', ''));

            return;
        }

        self::syncPlannedQuantityFromInvestment($set, $get, $record);
    }

    private static function plannedInvestmentHelperText(Get $get, ?Position $record): ?string
    {
        if (! self::userHasBankroll()) {
            return null;
        }

        $bankroll = (float) auth()->user()->trading_bankroll;
        $limitPercent = (float) (auth()->user()?->default_risk_percent ?? 1);
        $riskLimit = PositionSizing::resolveRiskLimitFromProfile($bankroll, $limitPercent);

        if ($riskLimit === null) {
            return null;
        }

        $percentLabel = rtrim(rtrim(number_format($limitPercent, 2), '0'), '.');

        return "Limiet: {$percentLabel}% van $".number_format($bankroll, 2).' = $'.number_format($riskLimit, 2).' max risico';
    }

    private static function userHasBankroll(): bool
    {
        $bankroll = auth()->user()?->trading_bankroll;

        return $bankroll !== null && (float) $bankroll > 0;
    }

    /**
     * @return array{
     *     bankroll: ?float,
     *     limitPercent: float,
     *     riskLimit: ?float,
     *     plannedRisk: ?float,
     *     riskPercentOfBankroll: ?float,
     *     exceeds: bool,
     *     overByPercentPoints: ?float
     * }
     */
    private static function resolveRiskGuardState(Get $get, ?Position $record): array
    {
        $bankroll = self::userHasBankroll() ? (float) auth()->user()->trading_bankroll : null;
        $limitPercent = (float) (auth()->user()?->default_risk_percent ?? 1);
        $riskLimit = PositionSizing::resolveRiskLimitFromProfile($bankroll, $limitPercent);
        $entry = $get('entry_price') ?? $record?->entry_price;
        $quantity = $get('quantity') ?? $record?->quantity;
        $stopLoss = self::resolveComputedNewSl($get, $record);

        $plannedRisk = null;
        $riskPercentOfBankroll = null;
        $overByPercentPoints = null;

        if ($entry !== null && $entry !== '' && $quantity !== null && $quantity !== '' && $stopLoss !== null) {
            $plannedRisk = PositionSizing::plannedRiskTotal(
                (int) floor((float) $quantity),
                (float) $entry,
                $stopLoss,
                self::resolveFormDirection($get, $record),
            );
        }

        if ($plannedRisk !== null && $bankroll !== null) {
            $riskPercentOfBankroll = PositionSizing::riskAsPercentOfBankroll($plannedRisk, $bankroll);
            $overByPercentPoints = PositionSizing::overLimitByPercentPoints($riskPercentOfBankroll, $limitPercent);
        }

        return [
            'bankroll' => $bankroll,
            'limitPercent' => $limitPercent,
            'riskLimit' => $riskLimit,
            'plannedRisk' => $plannedRisk,
            'riskPercentOfBankroll' => $riskPercentOfBankroll,
            'exceeds' => $plannedRisk !== null && PositionSizing::exceedsRiskLimit($plannedRisk, $riskLimit),
            'overByPercentPoints' => $overByPercentPoints,
        ];
    }

    private static function isScoutSizingContext(?Position $record): bool
    {
        return self::$scoutFormMode || $record?->status === 'scout';
    }

    private static function syncBuyStopFromInputs(Set $set, Get $get, ?Position $record): void
    {
        self::syncEntryStopFromSignal($set, $get, $record);
    }

    private static function syncEntryStopFromSignal(Set $set, Get $get, ?Position $record): void
    {
        $direction = self::resolveFormDirection($get, $record);

        if ($direction === TradeDirection::Short) {
            $sellStop = Position::computeSellStop(
                $get('signal_low') ?? $record?->signal_low,
                $get('latest_atr_14') ?? $record?->latest_atr_14,
            );

            if ($sellStop === null) {
                return;
            }

            $set('entry_price', $sellStop);
            self::syncPlannedQuantityFromInvestment($set, $get, $record);

            return;
        }

        $buyStop = Position::computeBuyStop(
            $get('signal_high') ?? $record?->signal_high,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
        );

        if ($buyStop === null) {
            return;
        }

        $set('entry_price', $buyStop);
        self::syncPlannedQuantityFromInvestment($set, $get, $record);
    }

    private static function resolveFormDirection(Get $get, ?Position $record): TradeDirection
    {
        $raw = $get('direction') ?? $record?->direction?->value ?? TradeDirection::Long->value;

        return TradeDirection::tryFrom((string) $raw) ?? TradeDirection::Long;
    }

    /**
     * @return array{
     *     totalPoints: int,
     *     maxPoints: int,
     *     grade: string,
     *     gradeLabel: string,
     *     hardFailReasons: array<int, string>,
     *     criteria: array<int, array{
     *         key: string,
     *         label: string,
     *         points: int,
     *         maxPoints: int,
     *         status: string,
     *         detail: string,
     *     }>,
     * }
     */
    private static function resolveScorecard(Get $get, ?Position $record): array
    {
        $inputs = self::scorecardInputs($get, $record);

        if ($record !== null) {
            return $record->evaluateSetupScore($inputs);
        }

        return ScoutSetupScorecard::evaluate($inputs);
    }

    private static function scorecardGradeColor(string $grade): string
    {
        return match ($grade) {
            'A++' => 'success',
            'A' => 'success',
            'B' => 'warning',
            'C' => 'gray',
            default => 'danger',
        };
    }

    /**
     * @param  array{grade: string, hardFailReasons: array<int, string>}  $score
     */
    private static function scorecardCardVariant(array $score): string
    {
        if ($score['hardFailReasons'] !== []) {
            return 'rose';
        }

        return match ($score['grade']) {
            'A++' => 'a-plus',
            'A' => 'a',
            'B' => 'zinc',
            'C' => 'zinc',
            default => 'rose',
        };
    }

    /**
     * @param  array{grade: string, hardFailReasons: array<int, string>}  $score
     */
    private static function scorecardHudTone(array $score): string
    {
        if ($score['hardFailReasons'] !== []) {
            return 'no-trade';
        }

        return match ($score['grade']) {
            'A++' => 'a-plus',
            'A' => 'a',
            'B' => 'b',
            'C' => 'c',
            default => 'no-trade',
        };
    }

    private static function resolveActionCardVariant(Get $get, ?Position $record): string
    {
        return match (self::resolveFormActionCommand($get, $record)) {
            'STOPPED OUT' => 'rose',
            'UPDATE' => 'amber',
            'HOLD' => 'vestix',
            default => 'zinc',
        };
    }

    private static function resolvePnlCardVariant(Get $get, ?Position $record): string
    {
        return match (self::resolvePerformanceColor($get, $record)) {
            'success' => 'vestix',
            'danger' => 'rose',
            default => 'zinc',
        };
    }

    private static function scorecardCriterionEntry(string $key): TextEntry
    {
        return TextEntry::make('scorecard_'.$key)
            ->label(fn (Get $get, ?Position $record): string => self::formatScorecardCriterionLabel($get, $record, $key))
            ->state(fn (Get $get, ?Position $record): string => self::scorecardCriterion($get, $record, $key)['detail'] ?? '—')
            ->hintIcon(fn (Get $get, ?Position $record): string => self::scorecardCriterionStatusIcon(
                self::scorecardCriterion($get, $record, $key)['status'] ?? null,
            ))
            ->hintColor(fn (Get $get, ?Position $record): string => self::scorecardCriterionStatusColor(
                self::scorecardCriterion($get, $record, $key)['status'] ?? null,
            ))
            ->extraEntryWrapperAttributes(fn (Get $get, ?Position $record): array => [
                'class' => 'scout-scorecard-criterion scout-scorecard-criterion--'.(
                    self::scorecardCriterion($get, $record, $key)['status'] ?? 'fail'
                ),
            ]);
    }

    /**
     * @return array{key: string, label: string, points: int, maxPoints: int, status: string, detail: string}|null
     */
    private static function scorecardCriterion(Get $get, ?Position $record, string $key): ?array
    {
        foreach (self::resolveScorecard($get, $record)['criteria'] as $criterion) {
            if ($criterion['key'] === $key) {
                return $criterion;
            }
        }

        return null;
    }

    private static function formatScorecardCriterionLabel(Get $get, ?Position $record, string $key): string
    {
        $criterion = self::scorecardCriterion($get, $record, $key);

        if ($criterion === null) {
            return $key;
        }

        return "{$criterion['label']} ({$criterion['points']}/{$criterion['maxPoints']})";
    }

    private static function scorecardCriterionStatusIcon(?string $status): string
    {
        return match ($status) {
            'pass' => 'heroicon-m-check-circle',
            'warn' => 'heroicon-m-minus-circle',
            default => 'heroicon-m-x-circle',
        };
    }

    private static function scorecardCriterionStatusColor(?string $status): string
    {
        return match ($status) {
            'pass' => 'success',
            'warn' => 'warning',
            default => 'danger',
        };
    }

    private static function cockpitSection(): Section
    {
        return Section::make()
            ->visible(fn (string $operation, ?Position $record): bool => $operation === 'edit'
                && $record?->status !== 'scout')
            ->columnSpanFull()
            ->contained(false)
            ->schema([
                Grid::make(4)
                    ->extraAttributes(['class' => 'position-cockpit-grid'])
                    ->schema(self::openCockpitCards())
                    ->columnSpanFull(),
            ]);
    }

    private static function scoutCockpitSection(): Section
    {
        return Section::make()
            ->visible(fn (string $operation, ?Position $record): bool => $operation === 'edit'
                && $record?->status === 'scout')
            ->columnSpanFull()
            ->contained(false)
            ->schema([
                Callout::make('Gap-up risico')
                    ->visible(fn (?Position $record): bool => $record?->hasPremarketGapUpRisk() ?? false)
                    ->description(fn (?Position $record): string => sprintf(
                        'Pas op, risico op chasing bij %s! Pre-market noteert $%s (boven bounce high $%s).',
                        $record?->ticker ?? '',
                        number_format((float) ($record?->premarket_price ?? 0), 2),
                        number_format((float) ($record?->premarket_reference_price ?? $record?->signal_high ?? 0), 2),
                    ))
                    ->danger()
                    ->icon('heroicon-o-exclamation-triangle'),
                Grid::make(4)
                    ->extraAttributes(['class' => 'position-cockpit-grid'])
                    ->schema(self::scoutCockpitPrimaryCards())
                    ->columnSpanFull(),
                Grid::make(4)
                    ->extraAttributes(['class' => 'position-cockpit-grid position-cockpit-grid--premarket'])
                    ->schema(self::scoutPremarketCards())
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, View>
     */
    private static function openCockpitCards(): array
    {
        return [
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => self::actueleKoersCardViewData($get, $record)),
            View::make('filament.positions.cockpit-schild-card')
                ->viewData(fn (Get $get, ?Position $record): array => self::schildCardViewData($get, $record)),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => self::positieWaardeCardViewData($get, $record)),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $metrics = self::resolvePerformanceMetrics($get, $record);
                    $pnlColor = self::resolvePerformanceColor($get, $record);
                    $pnlDescription = null;
                    $pnlIcon = null;

                    if ($metrics !== null) {
                        $pnlPctPrefix = $metrics['pnl_percentage'] >= 0 ? '+' : '';
                        $pnlDescription = $pnlPctPrefix.number_format($metrics['pnl_percentage'], 2).'% t.o.v. inleg';
                        $pnlIcon = $metrics['pnl'] >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
                    }

                    return [
                        'label' => $record?->status === 'closed' ? 'Definitieve P&L' : 'Open P&L',
                        'value' => self::formatSignedPnl($get, $record),
                        'valueColor' => $pnlColor !== 'gray' ? $pnlColor : null,
                        'description' => $pnlDescription,
                        'descriptionColor' => $pnlColor,
                        'descriptionIcon' => $pnlIcon,
                        'cardVariant' => self::resolvePnlCardVariant($get, $record),
                    ];
                }),
            View::make('filament.positions.cockpit-stat-card')
                ->visible(fn (?Position $record): bool => $record !== null && EarningsExitDisplay::isRelevant($record))
                ->viewData(fn (Get $get, ?Position $record): array => EarningsExitDisplay::cockpitCardData($record)
                    ?? [
                        'label' => 'Earnings',
                        'value' => '—',
                        'cardVariant' => 'zinc',
                    ]),
        ];
    }

    /**
     * @return array<int, View>
     */
    private static function scoutCockpitPrimaryCards(): array
    {
        return [
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => self::actueleKoersCardViewData($get, $record)),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => self::geplandeEntryCardViewData($get, $record)),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $investment = self::formatPlannedInvestment($get, $record);

                    return [
                        'label' => 'Totale inleg',
                        'value' => $investment['value'],
                        'description' => $investment['text'] ?? null,
                        'descriptionColor' => 'gray',
                        'cardVariant' => 'zinc',
                    ];
                }),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $risk = self::formatPlannedRiskDescription($get, $record);

                    return [
                        'label' => 'Gepland risico',
                        'value' => $risk['value'] ?? '—',
                        'valueColor' => $risk['valueColor'] ?? null,
                        'description' => $risk['text'] ?? null,
                        'descriptionColor' => $risk['color'] ?? 'gray',
                        'secondaryDescription' => $risk['secondaryDescription'] ?? null,
                        'cardVariant' => $risk['cardVariant'] ?? 'amber',
                    ];
                }),
        ];
    }

    /**
     * @return array<int, View>
     */
    private static function scoutPremarketCards(): array
    {
        return [
            View::make('filament.positions.cockpit-stat-card')
                ->visible(fn (?Position $record): bool => $record !== null && PremarketGatekeeperDisplay::isRelevant($record))
                ->viewData(fn (Get $get, ?Position $record): array => PremarketGatekeeperDisplay::cockpitCardData($record)
                    ?? [
                        'label' => 'Pre-Market',
                        'value' => '—',
                        'cardVariant' => 'blue',
                    ]),
        ];
    }

    private static function formatSignedPnl(Get $get, ?Position $record): string
    {
        $metrics = self::resolvePerformanceMetrics($get, $record);

        if ($metrics === null) {
            return '—';
        }

        $prefix = $metrics['pnl'] >= 0 ? '+' : '-';

        return $prefix.'$'.number_format(abs($metrics['pnl']), 2);
    }

    private static function resolvePerformanceColor(Get $get, ?Position $record): string
    {
        $metrics = self::resolvePerformanceMetrics($get, $record);

        if ($metrics === null) {
            return 'gray';
        }

        return $metrics['pnl'] >= 0 ? 'success' : 'danger';
    }

    /**
     * @return array{pnl: float, pnl_percentage: float}|null
     */
    private static function resolvePerformanceMetrics(Get $get, ?Position $record): ?array
    {
        if ($record !== null && in_array($record->status, ['open', 'closed'], true)) {
            if ($record->status === 'open' && ($get('latest_close_price') ?? $record->latest_close_price) !== null) {
                $preview = clone $record;
                $preview->latest_close_price = $get('latest_close_price') ?? $record->latest_close_price;
                $preview->entry_price = $get('entry_price') ?? $record->entry_price;
                $preview->quantity = $get('quantity') ?? $record->quantity;

                return [
                    'pnl' => $preview->unrealized_pnl,
                    'pnl_percentage' => $preview->unrealized_pnl_percentage,
                ];
            }

            if ($record->status === 'closed' || $record->valuation_price !== null) {
                return [
                    'pnl' => $record->unrealized_pnl,
                    'pnl_percentage' => $record->unrealized_pnl_percentage,
                ];
            }
        }

        $price = $record?->status === 'closed'
            ? $record->exit_price
            : ($get('latest_close_price') ?? $record?->latest_close_price);

        if ($price === null || $price === '') {
            return null;
        }

        $entry = (float) ($get('entry_price') ?? $record?->entry_price ?? 0);
        $quantity = (float) ($get('quantity') ?? $record?->quantity ?? 0);

        if ($entry <= 0 || $quantity <= 0) {
            return null;
        }

        $price = (float) $price;
        $direction = self::resolveFormDirection($get, $record);
        $pnl = $direction === TradeDirection::Short
            ? ($entry - $price) * $quantity
            : ($price - $entry) * $quantity;
        $pnlPercentage = $direction === TradeDirection::Short
            ? (($entry - $price) / $entry) * 100
            : (($price - $entry) / $entry) * 100;

        return [
            'pnl' => $pnl,
            'pnl_percentage' => $pnlPercentage,
        ];
    }

    private static function resolveFormActionCommand(Get $get, ?Position $record): string
    {
        $position = self::resolvePositionForProtocol($get, $record);

        return Position::resolveActionCommand(
            $get('latest_close_price') ?? $record?->latest_close_price,
            $get('current_sl') ?? $record?->current_sl,
            $get('latest_sma_20') ?? $record?->latest_sma_20,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
            $position,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function marketFieldOverrides(Get $get, ?Position $record): array
    {
        return [
            'latest_sma_20' => $get('latest_sma_20') ?? $record?->latest_sma_20,
            'latest_atr_14' => $get('latest_atr_14') ?? $record?->latest_atr_14,
            'latest_close_price' => $get('latest_close_price') ?? $record?->latest_close_price,
            'scout_rsi' => $get('scout_rsi') ?? $record?->scout_rsi,
            'prior_day_low' => $get('prior_day_low') ?? $record?->prior_day_low,
            'current_sl' => $get('current_sl') ?? $record?->current_sl,
            'direction' => self::resolveFormDirection($get, $record)->value,
        ];
    }

    private static function resolvePositionForProtocol(Get $get, ?Position $record): ?Position
    {
        if ($record === null || $record->status !== 'open') {
            return null;
        }

        return StopLossProtocol::applyOverrides($record, self::marketFieldOverrides($get, $record));
    }

    /**
     * @return array<string, mixed>
     */
    private static function actueleKoersCardViewData(Get $get, ?Position $record): array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $chart = self::resolveLiveCloseChart($get, $record);
        $trend = self::resolveCloseDayTrend($close, $chart, $record?->recent_close_prices ?? []);

        return [
            'label' => 'Actuele Koers',
            'value' => self::formatUsd($close),
            'description' => $trend['description'],
            'descriptionColor' => $trend['color'],
            'descriptionIcon' => $trend['icon'],
            'chart' => count($chart) >= 2 ? $chart : null,
            'chartColor' => $trend['color'] !== 'gray' ? $trend['color'] : 'gray',
            'cardVariant' => 'blue',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function positieWaardeCardViewData(Get $get, ?Position $record): array
    {
        $positionValue = self::formatPositionValueCard($get, $record);
        $lockedProfit = self::resolveLockedInProfitDollars($get, $record);

        if ($lockedProfit > 0) {
            $description = '+$'.number_format($lockedProfit, 2).' risicovrij';
            $descriptionIcon = 'heroicon-m-lock-closed';
            $descriptionColor = 'info';
        } else {
            $freerideGap = self::resolveFreerideGap($get, $record);
            $description = FreerideDisplay::distanceSubtext($freerideGap) ?? 'Geen veiliggestelde winst';
            $descriptionIcon = 'heroicon-m-lock-open';
            $descriptionColor = 'gray';
        }

        return [
            'label' => 'Positiewaarde',
            'value' => $positionValue['value'],
            'description' => $description,
            'descriptionColor' => $descriptionColor,
            'descriptionIcon' => $descriptionIcon,
            'cardVariant' => 'blue',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schildCardViewData(Get $get, ?Position $record): array
    {
        $action = self::resolveFormActionCommand($get, $record);
        $displaySl = self::resolveSchildCardSl($get, $record);
        $actionDescription = self::formatSchildSubtext($get, $record);
        $position = self::resolvePositionForProtocol($get, $record);

        return [
            'action' => $action,
            'label' => 'Berekende SL',
            'showOverboughtBadge' => $position !== null && StopLossProtocol::isRsiOverbought($position),
            'value' => self::formatUsd($displaySl),
            'copyValue' => self::formatNewSlCopyValue($displaySl),
            'valuePulse' => $action === 'UPDATE',
            'description' => $actionDescription['text'] ?? null,
            'descriptionColor' => $actionDescription['color'] ?? 'gray',
            'cardVariant' => self::resolveActionCardVariant($get, $record),
        ];
    }

    /**
     * @return array<int, float>
     */
    private static function resolveLiveCloseChart(Get $get, ?Position $record): array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;

        if ($close === null || $close === '') {
            return [];
        }

        $history = $record?->recent_close_prices ?? [];

        if ($history === []) {
            return [round((float) $close, 2)];
        }

        $chart = array_map(static fn (mixed $price): float => round((float) $price, 2), $history);
        $chart[count($chart) - 1] = round((float) $close, 2);

        return $chart;
    }

    /**
     * @param  array<int, float>  $chart
     * @param  array<int, float|int|string>  $storedHistory
     * @return array{
     *     description: ?string,
     *     icon: ?string,
     *     color: string,
     *     chart: ?array<int, float>,
     * }
     */
    private static function resolveCloseDayTrend(mixed $close, array $chart, array $storedHistory): array
    {
        if ($close === null || $close === '') {
            return [
                'description' => null,
                'icon' => null,
                'color' => 'gray',
                'chart' => null,
            ];
        }

        $trend = ClosePriceTrend::resolveDayChange((float) $close, $storedHistory);

        if ($trend === null) {
            return [
                'description' => null,
                'icon' => null,
                'color' => 'gray',
                'chart' => count($chart) >= 2 ? $chart : null,
            ];
        }

        return [
            'description' => $trend['description'],
            'icon' => $trend['icon'],
            'color' => $trend['color'],
            'chart' => count($chart) >= 2 ? $chart : null,
        ];
    }

    /**
     * @return array{percentage: float, dollars: float}|null
     */
    private static function resolveFreerideGap(Get $get, ?Position $record): ?array
    {
        if ($record?->status === 'closed') {
            return null;
        }

        $entry = $get('entry_price') ?? $record?->entry_price;
        $currentSl = $get('current_sl') ?? $record?->current_sl;
        $quantity = $get('quantity') ?? $record?->quantity;

        if ($entry === null || $entry === '' || $currentSl === null || $currentSl === '' || $quantity === null || $quantity === '') {
            return null;
        }

        return FreerideDisplay::gapToFreeride((float) $entry, (float) $currentSl, (float) $quantity);
    }

    private static function resolveLockedInProfitDollars(Get $get, ?Position $record): float
    {
        if ($record?->status === 'closed') {
            return 0;
        }

        $entry = $get('entry_price') ?? $record?->entry_price;
        $currentSl = $get('current_sl') ?? $record?->current_sl;
        $quantity = $get('quantity') ?? $record?->quantity;

        if ($entry === null || $entry === '' || $currentSl === null || $currentSl === '' || $quantity === null || $quantity === '') {
            return 0;
        }

        if ((float) $currentSl <= (float) $entry) {
            return 0;
        }

        return ((float) $currentSl - (float) $entry) * (float) $quantity;
    }

    /**
     * @return array{text: string, color: string}|null
     */
    private static function formatSchildSubtext(Get $get, ?Position $record): ?array
    {
        $action = self::resolveFormActionCommand($get, $record);

        if ($action === 'STOPPED OUT') {
            return [
                'text' => 'Liquidatie · onder SL',
                'color' => 'danger',
            ];
        }

        if ($action === 'AWAITING DATA') {
            return null;
        }

        $position = self::resolvePositionForProtocol($get, $record);

        if ($action === 'HOLD' && $position !== null && StopLossProtocol::isRsiOverbought($position)) {
            $mode = StopLossProtocol::activeMode($position);

            return [
                'text' => match ($mode) {
                    TrailingStopMode::AggressivePreEarnings => 'Pre-earnings escalatie actief',
                    default => 'Oververhit — agressief schild actief',
                },
                'color' => $mode === TrailingStopMode::AggressivePreEarnings ? 'danger' : 'warning',
            ];
        }

        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $protocolSl = self::resolveComputedNewSl($get, $record);
        $displaySl = self::resolveSchildCardSl($get, $record);

        if ($close === null || $close === '' || $displaySl === null) {
            return null;
        }

        $close = (float) $close;
        $atr = $get('latest_atr_14') ?? $record?->latest_atr_14;
        $atr = $atr !== null && $atr !== '' ? (float) $atr : null;
        $percentage = ($displaySl - $close) / $close * 100;
        $percentagePrefix = $percentage >= 0 ? '+' : '−';
        $distanceText = $percentagePrefix.number_format(abs($percentage), 2).'% t.o.v. koers';

        if (self::isCurrentSlAboveTrailing($get, $record) && $protocolSl !== null) {
            return [
                'text' => $distanceText.' · trailing '.self::formatUsd($protocolSl),
                'color' => SlPriceProximity::color($close, $displaySl, $atr),
            ];
        }

        return [
            'text' => $distanceText,
            'color' => SlPriceProximity::color($close, $displaySl, $atr),
        ];
    }

    private static function resolveSchildCardSl(Get $get, ?Position $record): ?float
    {
        $protocolSl = self::resolveComputedNewSl($get, $record);

        if (self::isCurrentSlAboveTrailing($get, $record)) {
            return (float) ($get('current_sl') ?? $record?->current_sl);
        }

        return $protocolSl;
    }

    private static function isCurrentSlAboveTrailing(Get $get, ?Position $record): bool
    {
        if (self::resolveFormActionCommand($get, $record) !== 'HOLD') {
            return false;
        }

        $protocolSl = self::resolveComputedNewSl($get, $record);
        $currentSl = $get('current_sl') ?? $record?->current_sl;

        return $protocolSl !== null
            && $currentSl !== null
            && $currentSl !== ''
            && (float) $currentSl > $protocolSl;
    }

    /**
     * @return array{value: string, text: ?string}
     */
    private static function formatPositionValueCard(Get $get, ?Position $record): array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $entry = $get('entry_price') ?? $record?->entry_price;
        $quantity = $get('quantity') ?? $record?->quantity;

        if ($close === null || $close === '' || $entry === null || $entry === '' || $quantity === null || $quantity === '') {
            return ['value' => '—', 'text' => null];
        }

        $currentValue = (float) $close * (float) $quantity;
        $investment = (float) $entry * (float) $quantity;

        return [
            'value' => self::formatUsd($currentValue),
            'text' => 'Inleg: '.self::formatUsd($investment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function geplandeEntryCardViewData(Get $get, ?Position $record): array
    {
        $entry = $get('entry_price') ?? $record?->entry_price;
        $distance = self::formatGeplandeEntryDistanceDescription($get, $record);

        return [
            'label' => 'Geplande Entry',
            'value' => self::formatUsd($entry),
            'copyValue' => self::formatNewSlCopyValue($entry !== null && $entry !== '' ? (float) $entry : null),
            'description' => $distance['text'] ?? null,
            'descriptionColor' => $distance['color'] ?? 'gray',
            'cardVariant' => 'amber',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function berekendeSlCardViewData(Get $get, ?Position $record): array
    {
        $newSl = self::resolveComputedNewSl($get, $record);
        $distance = self::formatNewSlDistanceDescription($get, $record);

        return [
            'label' => 'Berekende SL',
            'value' => self::formatUsd($newSl),
            'copyValue' => self::formatNewSlCopyValue($newSl),
            'description' => $distance['text'] ?? null,
            'descriptionColor' => $distance['color'] ?? 'gray',
            'cardVariant' => 'amber',
        ];
    }

    private static function resolveComputedNewSl(Get $get, ?Position $record): ?float
    {
        if ($record !== null && $record->status === 'open') {
            return StopLossProtocol::resolveWithOverrides($record, self::marketFieldOverrides($get, $record));
        }

        return StopLossProtocol::resolveForIndicators(
            $get('latest_sma_20') ?? $record?->latest_sma_20,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
            self::resolveFormDirection($get, $record),
        );
    }

    private static function formatNewSlCopyValue(?float $newSl): ?string
    {
        if ($newSl === null) {
            return null;
        }

        return number_format($newSl, 2, '.', '');
    }

    /**
     * @return array{text: string, color: string}|null
     */
    private static function formatGeplandeEntryDistanceDescription(Get $get, ?Position $record): ?array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $entry = $get('entry_price') ?? $record?->entry_price;

        if ($close === null || $close === '' || $entry === null || $entry === '') {
            return null;
        }

        $close = (float) $close;
        $entry = (float) $entry;
        $percentage = (($entry - $close) / $close) * 100;

        // Voor entry gebruiken we dezelfde kleurlogica als SL maar dan omgedraaid? Nee, we kijken gewoon
        // naar "hoe ver is de entry nog weg t.o.v. de actuele koers".
        // Eigenlijk is de kleur op de radar voor entry vaak neutraal, maar we houden het op amber/danger bij break.
        $color = 'gray'; // Default if not using SlPriceProximity
        $atr = $get('latest_atr_14') ?? $record?->latest_atr_14;
        $atr = $atr !== null && $atr !== '' ? (float) $atr : null;
        if ($atr !== null) {
            $color = SlPriceProximity::color($close, $entry, $atr);
        }

        $percentagePrefix = $percentage >= 0 ? '+' : '−';

        return [
            'text' => $percentagePrefix.number_format(abs($percentage), 2).'% t.o.v. koers',
            'color' => $color,
        ];
    }

    /**
     * @return array{text: string, color: string}|null
     */
    private static function formatNewSlDistanceDescription(Get $get, ?Position $record): ?array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $newSl = self::resolveComputedNewSl($get, $record);

        if ($close === null || $close === '' || $newSl === null) {
            return null;
        }

        $close = (float) $close;
        $newSl = (float) $newSl;
        $percentage = (($newSl - $close) / $close) * 100;
        $atr = $get('latest_atr_14') ?? $record?->latest_atr_14;
        $atr = $atr !== null && $atr !== '' ? (float) $atr : null;
        $percentagePrefix = $percentage >= 0 ? '+' : '−';

        return [
            'text' => $percentagePrefix.number_format(abs($percentage), 2).'% t.o.v. koers',
            'color' => SlPriceProximity::color($close, $newSl, $atr),
        ];
    }

    /**
     * @return array{value: string, valueColor: ?string, text: ?string, color: string, cardVariant: string, secondaryDescription?: array{text: string, color?: string}}|null
     */
    private static function formatPlannedRiskDescription(Get $get, ?Position $record): ?array
    {
        $entry = $get('entry_price') ?? $record?->entry_price;
        $quantity = $get('quantity') ?? $record?->quantity;
        $newSl = self::resolveComputedNewSl($get, $record);

        if ($entry === null || $entry === '' || $newSl === null) {
            return null;
        }

        $perShare = round((float) $entry - $newSl, 2);
        $percentage = ($perShare / (float) $entry) * 100;
        $tradeRiskLabel = rtrim(rtrim(number_format($percentage, 2), '0'), '.');
        $tradeRiskSecondary = [
            'text' => "{$tradeRiskLabel}% daling tot SL",
        ];
        $guard = self::resolveRiskGuardState($get, $record);

        if ($guard['plannedRisk'] !== null && $guard['bankroll'] !== null && $guard['riskPercentOfBankroll'] !== null) {
            $riskPctLabel = rtrim(rtrim(number_format($guard['riskPercentOfBankroll'], 1), '0'), '.');

            if ($guard['exceeds'] && $guard['overByPercentPoints'] !== null) {
                $overLabel = rtrim(rtrim(number_format($guard['overByPercentPoints'], 1), '0'), '.');

                return [
                    'value' => self::formatUsd($guard['plannedRisk']),
                    'valueColor' => 'danger',
                    'text' => "{$riskPctLabel}% van bankroll · {$overLabel}% boven limiet",
                    'color' => 'danger',
                    'secondaryDescription' => array_merge($tradeRiskSecondary, ['color' => 'danger']),
                    'cardVariant' => 'rose',
                ];
            }

            return [
                'value' => self::formatUsd($guard['plannedRisk']),
                'valueColor' => 'success',
                'text' => "{$riskPctLabel}% van bankroll",
                'color' => 'success',
                'secondaryDescription' => array_merge($tradeRiskSecondary, ['color' => 'success']),
                'cardVariant' => 'green',
            ];
        }

        if ($quantity !== null && $quantity !== '') {
            $totalRisk = $perShare * (float) $quantity;

            return [
                'value' => self::formatUsd($totalRisk),
                'valueColor' => 'warning',
                'text' => self::formatUsd($perShare).'/aandeel · '.number_format((float) $quantity, 0).' stuks',
                'color' => 'warning',
                'cardVariant' => 'amber',
            ];
        }

        return [
            'value' => self::formatUsd($perShare),
            'valueColor' => $perShare > 0 ? 'warning' : 'danger',
            'text' => number_format($percentage, 2).'% t.o.v. entry',
            'color' => $perShare > 0 ? 'warning' : 'danger',
            'cardVariant' => 'amber',
        ];
    }

    /**
     * @return array{value: string, text: ?string}
     */
    private static function formatPlannedInvestment(Get $get, ?Position $record): array
    {
        $plannedInvestment = $get('_planned_investment');

        if (filled($plannedInvestment)) {
            $entry = $get('entry_price') ?? $record?->entry_price;
            $quantity = $get('quantity') ?? $record?->quantity;

            return [
                'value' => '$'.number_format((float) $plannedInvestment, 2),
                'text' => ($entry !== null && $entry !== '' && $quantity !== null && $quantity !== '')
                    ? number_format((float) $quantity, 0).' × '.self::formatUsd($entry)
                    : null,
            ];
        }

        $entry = $get('entry_price') ?? $record?->entry_price;
        $quantity = $get('quantity') ?? $record?->quantity;

        if ($entry === null || $entry === '' || $quantity === null || $quantity === '') {
            return ['value' => '—', 'text' => null];
        }

        $total = (float) $entry * (float) $quantity;

        return [
            'value' => '$'.number_format($total, 2),
            'text' => number_format((float) $quantity, 0).' × '.self::formatUsd($entry),
        ];
    }

    /**
     * @return array{text: ?string, color: string}|null
     */
    private static function formatActionDescription(Get $get, ?Position $record): ?array
    {
        $action = self::resolveFormActionCommand($get, $record);

        $brokerSlText = 'Huidige SL: '.self::formatUsd($get('current_sl') ?? $record?->current_sl);

        return match ($action) {
            'UPDATE' => [
                'text' => $brokerSlText,
                'color' => 'warning',
            ],
            'STOPPED OUT' => [
                'text' => 'Positie direct sluiten',
                'color' => 'danger',
            ],
            'HOLD' => [
                'text' => $brokerSlText,
                'color' => 'gray',
            ],
            default => null,
        };
    }

    private static function resolveActionValueColor(Get $get, ?Position $record): ?string
    {
        return match (self::resolveFormActionCommand($get, $record)) {
            'STOPPED OUT' => 'danger',
            'UPDATE' => 'warning',
            default => null,
        };
    }

    private static function formatActionLabel(string $state): string
    {
        return match ($state) {
            'UPDATE' => 'Update',
            'HOLD' => 'Hold',
            'STOPPED OUT' => 'Liquidatie',
            default => $state,
        };
    }

    private static function formatUsd(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = number_format((float) $value, 2);
        $prefix = (float) $value < 0 ? '-$' : '$';

        return $prefix.ltrim($formatted, '-');
    }

    /**
     * @return array{
     *     entry_price?: float|null,
     *     latest_sma_20?: float|null,
     *     sma_20_five_days_ago?: float|null,
     *     latest_sma_50?: float|null,
     *     scout_rsi?: float|null,
     *     bounce_volume_above_average?: bool|null,
     * }
     */
    /**
     * @param  (callable(?Position): bool)|null  $visible
     */
    private static function chartScreenshotField(
        string $field,
        string $label,
        string $imageLabel,
        ?callable $visible = null,
    ): Grid {
        return Grid::make(1)
            ->visible($visible ?? true)
            ->columnSpanFull()
            ->schema([
                View::make('filament.positions.chart-screenshot-preview')
                    ->viewData(fn (Get $get, ?Position $record): array => [
                        'url' => ChartScreenshotUpload::resolveUrl($get($field) ?? $record?->{$field}),
                        'label' => $imageLabel,
                    ])
                    ->visible(fn (Get $get, ?Position $record): bool => filled(
                        ChartScreenshotUpload::resolveUrl($get($field) ?? $record?->{$field})
                    ))
                    ->columnSpanFull(),
                ChartScreenshotUpload::make($field)
                    ->label($label)
                    ->extraFieldWrapperAttributes(['class' => 'position-form-chart-upload'])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function sectorEtfOverrideSelectOptions(): array
    {
        $options = [];

        foreach (config('vestix.sector_mapping', []) as $sector => $etf) {
            $etf = strtoupper((string) $etf);
            $options[$etf] = "{$sector} ({$etf})";
        }

        return $options;
    }

    private static function scorecardInputs(Get $get, ?Position $record): array
    {
        return [
            'direction' => self::resolveFormDirection($get, $record)->value,
            'signal_low' => $get('signal_low') ?? $record?->signal_low,
            'signal_high' => $get('signal_high') ?? $record?->signal_high,
            'latest_open_price' => $get('latest_open_price') ?? $record?->latest_open_price,
            'latest_close_price' => $get('latest_close_price') ?? $record?->latest_close_price,
            'latest_sma_20' => $get('latest_sma_20') ?? $record?->latest_sma_20,
            'sma_20_five_days_ago' => $get('sma_20_five_days_ago') ?? $record?->sma_20_five_days_ago,
            'sma_20_ten_days_ago' => $get('sma_20_ten_days_ago') ?? $record?->sma_20_ten_days_ago,
            'latest_sma_50' => $get('latest_sma_50') ?? $record?->latest_sma_50,
            'scout_rsi' => $get('scout_rsi') ?? $record?->scout_rsi,
            'bounce_volume_above_average' => (bool) ($get('bounce_volume_above_average') ?? $record?->bounce_volume_above_average),
            'relative_volume' => $get('relative_volume') ?? $record?->relative_volume,
            'bounce_day_volume' => $get('bounce_day_volume') ?? $record?->bounce_day_volume,
            'volume_sma_20' => $get('volume_sma_20') ?? $record?->volume_sma_20,
            'sector_etf' => $get('sector_etf') ?? $record?->sector_etf,
            'sector_trend_positive' => (bool) ($get('sector_trend_positive') ?? $record?->sector_trend_positive),
            'pre_bounce_extension_atr' => $get('pre_bounce_extension_atr') ?? $record?->pre_bounce_extension_atr,
            'days_until_earnings' => $record?->daysUntilEarnings(),
        ];
    }
}
