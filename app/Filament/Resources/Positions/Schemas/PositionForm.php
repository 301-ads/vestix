<?php

namespace App\Filament\Resources\Positions\Schemas;

use App\Enums\PositionVisibility;
use App\Models\Position;
use App\Services\SquadContext;
use App\Services\TradingViewSymbolService;
use App\Support\ChartScreenshotUpload;
use App\Support\ScoutSetupScorecard;
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
                                self::buyStopSection($isScoutForm),
                            ]),
                        Section::make('Trade Journal')
                            ->columnSpan(['lg' => 1])
                            ->compact()
                            ->extraAttributes(['class' => 'position-form-journal-section'])
                            ->schema([
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
        return Toggle::make('visibility')
            ->label('Deel met squad')
            ->inline(false)
            ->onIcon('heroicon-m-user-group')
            ->offIcon('heroicon-m-eye-slash')
            ->onColor('success')
            ->offColor('gray')
            ->formatStateUsing(fn ($state): bool => self::visibilityIsSquad($state))
            ->dehydrateStateUsing(fn (bool $state): string => $state
                ? PositionVisibility::Squad->value
                : PositionVisibility::Private->value)
            ->live()
            ->afterStateUpdated(function (bool $state, Set $set): void {
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
                Grid::make(2)
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
                            ->live(onBlur: true),
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
                            ->live(onBlur: true),
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
                Grid::make(3)
                    ->visible(fn (?Position $record): bool => $record?->status !== 'closed')
                    ->schema([
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
                    ]),
            ]);
    }

    /**
     * @param  callable(?Position, string): bool  $isScoutForm
     */
    private static function buyStopSection(callable $isScoutForm): Section
    {
        return Section::make('Executie & Valstrik')
            ->afterLabel([
                Icon::make('heroicon-o-information-circle')
                    ->tooltip(
                        "Low/High: dagkaars (1D) van de bounce in TradingView — niet intraday.\n"
                        ."Low: laagste punt van die dagkaars (trampoline-scorecard).\n"
                        ."Close: slotkoers bepaalt of trampoline gebroken is (Close < SMA 20 = geblokkeerd).\n"
                        ."High: hoogste punt van die dagkaars.\n"
                        ."Buy-Stop: High + 10% × ATR 14 (ATR staat in Setup).\n"
                        .'Zet de Buy-Stop exact zo in je broker.'
                    )
                    ->color('gray'),
            ])
            ->visible(fn (?Position $record, string $operation): bool => $isScoutForm($record, $operation))
            ->compact()
            ->columns(3)
            ->schema([
                TextInput::make('signal_low')
                    ->label('Low (Signaalkaars)')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->helperText('Laagste punt van de bounce-dagkaars (TradingView, timeframe 1D).')
                    ->live(onBlur: true),
                TextInput::make('signal_high')
                    ->label('High (Signaalkaars)')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->minValue(0.01)
                    ->helperText('Hoogste punt van de bounce-dagkaars (TradingView, timeframe 1D).')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncBuyStopFromInputs($set, $get, $record))
                    ->afterStateHydrated(fn (Set $set, Get $get, ?Position $record): mixed => self::syncBuyStopFromInputs($set, $get, $record)),
                TextInput::make('advised_entry')
                    ->label('Geadviseerde Buy-Stop')
                    ->prefix('$')
                    ->readOnly()
                    ->dehydrated(false)
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
                    ->description('Klik "Data ophalen" rechtsboven, of vul handmatig in')
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
                            ->live(),
                    ]),
                Section::make('Sluipschutter Scorecard')
                    ->description('Objectieve setup-beoordeling (max 7 punten)')
                    ->icon('heroicon-m-viewfinder-circle')
                    ->columnSpan(['default' => 12, 'lg' => 8])
                    ->schema([
                        View::make('filament.positions.scout-scorecard-hud')
                            ->viewData(fn (Get $get, ?Position $record): array => [
                                'score' => self::resolveScorecard($get, $record),
                                'scoreColor' => self::scorecardGradeColor(
                                    self::resolveScorecard($get, $record)['grade'],
                                ),
                            ]),
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
                Grid::make(4)
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
                ->viewData(function (Get $get, ?Position $record): array {
                    $entryProfit = self::formatEntryProfitDescription($get, $record);

                    return [
                        'label' => 'Actuele Koers',
                        'value' => self::formatUsd($get('latest_close_price') ?? $record?->latest_close_price),
                        'description' => $entryProfit['text'] ?? null,
                        'descriptionColor' => $entryProfit['color'] ?? 'gray',
                    ];
                }),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $distance = self::formatNewSlDistanceDescription($get, $record);

                    return [
                        'label' => 'Nieuwe SL',
                        'value' => self::formatUsd(Position::computeNewSl(
                            $get('latest_sma_20') ?? $record?->latest_sma_20,
                            $get('latest_atr_14') ?? $record?->latest_atr_14,
                        )),
                        'description' => $distance['text'] ?? null,
                        'descriptionColor' => $distance['color'] ?? 'gray',
                        'labelHintIcon' => 'heroicon-m-information-circle',
                        'labelHintTooltip' => self::formatFormulaTooltip($get, $record),
                    ];
                }),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $action = self::resolveFormActionCommand($get, $record);
                    $actionDetails = self::formatActionDescription($get, $record);
                    $actionColor = self::resolveActionValueColor($get, $record);

                    return [
                        'label' => 'Actie / Executie',
                        'value' => self::formatActionLabel($action),
                        'valueColor' => $actionColor,
                        'valuePulse' => $action === 'UPDATE',
                        'description' => $actionDetails['text'] ?? null,
                        'descriptionColor' => $actionDetails['color'] ?? 'gray',
                    ];
                }),
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
                    ];
                }),
        ];
    }

    /**
     * @return array<int, View>
     */
    private static function scoutCockpitCards(): array
    {
        return [
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(fn (Get $get, ?Position $record): array => [
                    'label' => 'Actuele Koers',
                    'value' => self::formatUsd($get('latest_close_price') ?? $record?->latest_close_price),
                ]),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $distance = self::formatNewSlDistanceDescription($get, $record);

                    return [
                        'label' => 'Berekende SL',
                        'value' => self::formatUsd(Position::computeNewSl(
                            $get('latest_sma_20') ?? $record?->latest_sma_20,
                            $get('latest_atr_14') ?? $record?->latest_atr_14,
                        )),
                        'description' => $distance['text'] ?? null,
                        'descriptionColor' => $distance['color'] ?? 'gray',
                        'labelHintIcon' => 'heroicon-m-information-circle',
                        'labelHintTooltip' => self::formatFormulaTooltip($get, $record),
                    ];
                }),
            View::make('filament.positions.cockpit-stat-card')
                ->viewData(function (Get $get, ?Position $record): array {
                    $risk = self::formatPlannedRiskDescription($get, $record);

                    return [
                        'label' => 'Risico bij entry',
                        'value' => $risk['value'] ?? '—',
                        'valueColor' => $risk['valueColor'] ?? null,
                        'description' => $risk['text'] ?? null,
                        'descriptionColor' => $risk['color'] ?? 'gray',
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
                    ];
                }),
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

    private static function formatFormulaTooltip(Get $get, ?Position $record): ?string
    {
        $sma = $get('latest_sma_20') ?? $record?->latest_sma_20;
        $atr = $get('latest_atr_14') ?? $record?->latest_atr_14;

        if ($sma === null || $atr === null || $sma === '' || $atr === '') {
            return null;
        }

        return 'Formule: '.self::formatUsd($sma).' - (0.5 × '.number_format((float) $atr, 2).')';
    }

    /**
     * @return array{text: string, color: string}|null
     */
    private static function formatEntryProfitDescription(Get $get, ?Position $record): ?array
    {
        $close = $get('latest_close_price') ?? $record?->latest_close_price;
        $entry = $get('entry_price') ?? $record?->entry_price;

        if ($close === null || $close === '' || $entry === null || $entry === '') {
            return null;
        }

        $perShare = (float) $close - (float) $entry;
        $prefix = $perShare >= 0 ? '+' : '-';

        return [
            'text' => 'Winst per aandeel: '.$prefix.self::formatUsd(abs($perShare)),
            'color' => $perShare >= 0 ? 'success' : 'danger',
        ];
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
        $distance = $close - $newSl;
        $percentage = (($newSl - $close) / $close) * 100;
        $bufferPercentage = ($distance / $close) * 100;

        $color = match (true) {
            $distance <= 0 => 'danger',
            $bufferPercentage < 2 => 'warning',
            default => 'gray',
        };

        return [
            'text' => 'Afstand: '.self::formatUsd($distance).' ('.number_format($percentage, 1).'%)',
            'color' => $color,
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
