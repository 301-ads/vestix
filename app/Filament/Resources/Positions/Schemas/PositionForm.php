<?php

namespace App\Filament\Resources\Positions\Schemas;

use App\Enums\PositionVisibility;
use App\Models\Position;
use App\Models\StrategyTag;
use App\Services\SquadContext;
use App\Services\TradingViewSymbolService;
use App\Support\ChartScreenshotUpload;
use App\Support\ClosePriceTrend;
use App\Support\PremarketGatekeeperDisplay;
use App\Support\ScoutSetupScorecard;
use App\Support\SlPriceProximity;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class PositionForm
{
    public static function configure(Schema $schema, bool $scoutMode = false): Schema
    {
        $isScoutForm = fn (?Position $record, string $operation): bool => $scoutMode || $record?->status === 'scout';

        return $schema
            ->components([
                self::cockpitSection(),
                self::scoutCockpitSection(),
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'position-form-columns'])
                    ->schema([
                        Grid::make(1)
                            ->columnSpan(['lg' => 2])
                            ->extraAttributes(['class' => 'position-form-setup-grid'])
                            ->schema([
                                self::setupDetailsSection($isScoutForm),
                                self::schildSection(),
                                self::buyStopSection($isScoutForm),
                            ]),
                        Section::make('Trade Journal')
                            ->columnSpan(['lg' => 1])
                            ->compact()
                            ->extraAttributes(['class' => 'position-form-journal-section'])
                            ->schema([
                                Select::make('strategy_tag_id')
                                    ->label('Strategy tag')
                                    ->options(fn (): array => StrategyTag::query()
                                        ->where('is_active', true)
                                        ->orderBy('sort_order')
                                        ->pluck('name', 'id')
                                        ->all())
                                    // ->required(fn (string $operation): bool => $operation === 'create')
                                    ->native(false)
                                    ->searchable()
                                    ->placeholder('Kies je setup-type')
                                    ->helperText('Optioneel — gebruikt door Strategy Coach voor edge-analyse.')
                                    ->columnSpanFull(),
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
                            ]),
                    ]),
                self::scoutScorecardSection($isScoutForm),
                self::scoutVisibilitySection($isScoutForm),
            ]);
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
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? str_replace(',', '.', $state) : null)
                            ->rules(function (?Position $record, string $operation) use ($isScoutForm): array {
                                return $isScoutForm($record, $operation)
                                    ? ['nullable', 'numeric', 'min:0.000001']
                                    : ['required', 'numeric', 'min:0.000001'];
                            })
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record))
                            ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record)),
                        self::totalInvestmentField(),
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
                            ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record))
                            ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncTotalInvestmentField($set, $get, $record)),
                        TextInput::make('current_sl')
                            ->label('Huidige stop-loss')
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
            ]);
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

    /**
     * @return array<int, TextInput>
     */
    private static function schildMarketDataFields(): array
    {
        return [
            TextInput::make('latest_close_price')
                ->label('Close prijs')
                ->numeric()
                ->prefix('$')
                ->live(),
            TextInput::make('latest_sma_20')
                ->label('SMA 20')
                ->numeric()
                ->prefix('$')
                ->live(onBlur: true),
            TextInput::make('latest_atr_14')
                ->label('ATR 14')
                ->numeric()
                ->prefix('$')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?Position $record): void {
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
                        .'Zet de Buy-Stop exact zo in je broker — nooit market buy.'
                    )
                    ->color('gray'),
            ])
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->compact()
            ->columns(3)
            ->schema([
                TextInput::make('signal_low')
                    ->label('Low (Signaalkaars)')
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->rules(['nullable', 'numeric', 'min:0.01'])
                    ->helperText('Optioneel tot bounce-dag. Laagste punt van de bounce-dagkaars (TradingView, 1D).')
                    ->live(onBlur: true),
                TextInput::make('signal_high')
                    ->label('High (Signaalkaars)')
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->rules(['nullable', 'numeric', 'min:0.01'])
                    ->helperText('Optioneel tot bounce-dag. Hoogste punt van de bounce-dagkaars (TradingView, 1D).')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncBuyStopFromInputs($set, $get, $record))
                    ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncBuyStopFromInputs($set, $get, $record)),
                TextInput::make('advised_entry')
                    ->label('Geadviseerde Buy-Stop')
                    ->prefix('$')
                    ->readOnly()
                    ->dehydrated(false)
                    ->placeholder(fn (Get $get, ?Position $record): string => blank($get('signal_high') ?? $record?->signal_high)
                        ? 'Vul High in om Buy-Stop te berekenen'
                        : '')
                    ->extraInputAttributes(['style' => 'font-weight: bold; color: #10b981;']),
            ]);
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

    private static function totalInvestmentField(): TextInput
    {
        return TextInput::make('_total_investment')
            ->label('Totale inleg')
            ->prefix('$')
            ->readOnly()
            ->dehydrated(false)
            ->placeholder('—')
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

    private static function syncBuyStopFromInputs(Set $set, Get $get, ?Position $record): void
    {
        $buyStop = Position::computeBuyStop(
            $get('signal_high') ?? $record?->signal_high,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
        );

        if ($buyStop === null) {
            return;
        }

        $set('advised_entry', $buyStop);
        $set('entry_price', $buyStop);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function scoutScorecardSection(callable $isScoutForm): Grid
    {
        return Grid::make(12)
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->columnSpanFull()
            ->schema([
                Section::make('Marktdata & Indicatoren')
                    ->description(fn (string $operation): string => $operation === 'create'
                        ? 'Vul handmatig in — na opslaan kun je rechtsboven "Data ophalen" gebruiken'
                        : 'Klik "Data ophalen" rechtsboven, of vul handmatig in')
                    ->columnSpan(['default' => 12, 'lg' => 4])
                    ->schema([
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
                            // ->helperText('Nodig voor SMA trend — uit TradingView of handmatig')
                            ->live(debounce: 300),
                        TextInput::make('latest_sma_50')
                            ->label('SMA 50')
                            ->numeric()
                            ->prefix('$')
                            // ->helperText('Nodig voor SMA trend — uit TradingView of handmatig')
                            ->live(debounce: 300),
                        Toggle::make('bounce_volume_above_average')
                            ->label('Volume op bounce-dag hoger dan 30-daags gemiddelde')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Automatisch bij Data ophalen (op bounce-dag)'),
                        TextInput::make('bounce_day_volume')
                            ->label('Volume bounce-dag')
                            ->numeric()
                            ->readOnly()
                            ->dehydrated(false)
                            ->visible(fn (Get $get, ?Position $record): bool => filled($get('bounce_day_volume') ?? $record?->bounce_day_volume)),
                        TextInput::make('avg_volume_30d')
                            ->label('Gem. volume (30D)')
                            ->numeric()
                            ->readOnly()
                            ->dehydrated(false)
                            ->visible(fn (Get $get, ?Position $record): bool => filled($get('avg_volume_30d') ?? $record?->avg_volume_30d)),
                    ]),
                Section::make('Sniper Scorecard')
                    ->description('Objectieve setup-beoordeling (max 7 punten)')
                    ->icon('heroicon-m-viewfinder-circle')
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        View::make('filament.positions.scout-scorecard-hud')
                            ->viewData(function (Get $get, ?Position $record): array {
                                $score = self::resolveScorecard($get, $record);

                                return [
                                    'score' => $score,
                                    'scoreColor' => self::scorecardGradeColor($score['grade']),
                                    'cardVariant' => self::scorecardCardVariant($score),
                                ];
                            }),
                        Callout::make('Setup geblokkeerd')
                            ->visible(fn (Get $get, ?Position $record): bool => self::resolveScorecard($get, $record)['hardFailReasons'] !== [])
                            ->description(fn (Get $get, ?Position $record): string => implode("\n", self::resolveScorecard($get, $record)['hardFailReasons']))
                            ->danger()
                            ->icon('heroicon-o-exclamation-triangle'),
                        Grid::make(2)
                            ->schema([
                                self::scorecardCriterionEntry('trampoline'),
                                self::scorecardCriterionEntry('sma_direction'),
                                self::scorecardCriterionEntry('rsi'),
                                self::scorecardCriterionEntry('volume'),
                            ]),
                    ]),
            ]);
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
        return ScoutSetupScorecard::evaluate(self::scorecardInputs($get, $record));
    }

    private static function scorecardGradeColor(string $grade): string
    {
        return match ($grade) {
            'A+' => 'success',
            'A-' => 'warning',
            default => 'gray',
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
            'A+' => 'vestix',
            'A-' => 'amber',
            default => 'zinc',
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
            default => 'gray',
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
                Callout::make('premarket_gap_warning')
                    ->visible(fn (?Position $record): bool => $record?->hasPremarketGapUpRisk() ?? false)
                    ->color('danger')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->title('Gap-up risico')
                    ->description(fn (?Position $record): string => sprintf(
                        'Let op: Pre-market noteert $%s. Dit is boven je entry-trigger ($%s). Risico op chasing!',
                        number_format((float) ($record?->premarket_price ?? 0), 2),
                        number_format((float) ($record?->premarket_entry_trigger ?? $record?->entry_price ?? 0), 2),
                    )),
                Grid::make(4)
                    ->extraAttributes(['class' => 'position-cockpit-grid'])
                    ->schema(self::scoutCockpitCards())
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
        ];
    }

    /**
     * @return array<int, View>
     */
    private static function scoutCockpitCards(): array
    {
        $cards = [
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => [
                    'label' => 'Actuele Koers',
                    'value' => self::formatUsd($get('latest_close_price') ?? $record?->latest_close_price),
                    'cardVariant' => 'blue',
                ]),
        ];

        $cards[] = View::make('filament.positions.cockpit-stat-card')
            ->visible(fn (?Position $record): bool => $record !== null && PremarketGatekeeperDisplay::isRelevant($record))
            ->viewData(fn (Get $get, ?Position $record): array => PremarketGatekeeperDisplay::cockpitCardData($record)
                ?? [
                    'label' => 'Pre-Market',
                    'value' => '—',
                    'cardVariant' => 'blue',
                ]);

        $cards = array_merge($cards, [
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => self::berekendeSlCardViewData($get, $record)),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $risk = self::formatPlannedRiskDescription($get, $record);

                    return [
                        'label' => 'Risico bij entry',
                        'value' => $risk['value'] ?? '—',
                        'valueColor' => $risk['valueColor'] ?? null,
                        'description' => $risk['text'] ?? null,
                        'descriptionColor' => $risk['color'] ?? 'gray',
                        'cardVariant' => 'amber',
                    ];
                }),
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
        ]);

        return $cards;
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
        $pnl = ($price - $entry) * $quantity;
        $pnlPercentage = (($price - $entry) / $entry) * 100;

        return [
            'pnl' => $pnl,
            'pnl_percentage' => $pnlPercentage,
        ];
    }

    private static function resolveFormActionCommand(Get $get, ?Position $record): string
    {
        return Position::resolveActionCommand(
            $get('latest_close_price') ?? $record?->latest_close_price,
            $get('current_sl') ?? $record?->current_sl,
            $get('latest_sma_20') ?? $record?->latest_sma_20,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
        );
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
            $description = 'Geen veiliggestelde winst';
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
        $newSl = self::resolveComputedNewSl($get, $record);
        $actionDescription = self::formatSchildSubtext($get, $record);

        return [
            'action' => $action,
            'value' => self::formatUsd($newSl),
            'copyValue' => self::formatNewSlCopyValue($newSl),
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

        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $newSl = self::resolveComputedNewSl($get, $record);

        if ($close === null || $close === '' || $newSl === null) {
            return null;
        }

        $close = (float) $close;
        $atr = $get('latest_atr_14') ?? $record?->latest_atr_14;
        $atr = $atr !== null && $atr !== '' ? (float) $atr : null;
        $percentage = (((float) $newSl) - $close) / $close * 100;
        $percentagePrefix = $percentage >= 0 ? '+' : '−';

        return [
            'text' => $percentagePrefix.number_format(abs($percentage), 2).'% t.o.v. koers',
            'color' => SlPriceProximity::color($close, (float) $newSl, $atr),
        ];
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
        return Position::computeNewSl(
            $get('latest_sma_20') ?? $record?->latest_sma_20,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
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
    private static function formatNewSlDistanceDescription(Get $get, ?Position $record): ?array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $newSl = Position::computeNewSl(
            $get('latest_sma_20') ?? $record?->latest_sma_20,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
        );

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
            'text' => self::formatUsd($close).' · '.$percentagePrefix.number_format(abs($percentage), 1).'%',
            'color' => SlPriceProximity::color($close, $newSl, $atr),
        ];
    }

    /**
     * @return array{value: string, valueColor: ?string, text: ?string, color: string}|null
     */
    private static function formatPlannedRiskDescription(Get $get, ?Position $record): ?array
    {
        $entry = $get('entry_price') ?? $record?->entry_price;
        $newSl = Position::computeNewSl(
            $get('latest_sma_20') ?? $record?->latest_sma_20,
            $get('latest_atr_14') ?? $record?->latest_atr_14,
        );

        if ($entry === null || $entry === '' || $newSl === null) {
            return null;
        }

        $perShare = (float) $entry - $newSl;
        $percentage = ($perShare / (float) $entry) * 100;
        $color = $perShare > 0 ? 'warning' : 'danger';

        return [
            'value' => self::formatUsd($perShare),
            'valueColor' => $color,
            'text' => number_format($percentage, 2).'% t.o.v. entry',
            'color' => $color,
        ];
    }

    /**
     * @return array{value: string, text: ?string}
     */
    private static function formatPlannedInvestment(Get $get, ?Position $record): array
    {
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

    private static function scorecardInputs(Get $get, ?Position $record): array
    {
        return [
            'signal_low' => $get('signal_low') ?? $record?->signal_low,
            'latest_close_price' => $get('latest_close_price') ?? $record?->latest_close_price,
            'latest_sma_20' => $get('latest_sma_20') ?? $record?->latest_sma_20,
            'sma_20_five_days_ago' => $get('sma_20_five_days_ago') ?? $record?->sma_20_five_days_ago,
            'latest_sma_50' => $get('latest_sma_50') ?? $record?->latest_sma_50,
            'scout_rsi' => $get('scout_rsi') ?? $record?->scout_rsi,
            'bounce_volume_above_average' => (bool) ($get('bounce_volume_above_average') ?? $record?->bounce_volume_above_average),
        ];
    }
}
