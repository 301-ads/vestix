<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\VaultDeposit;
use App\Models\VaultSetting;
use App\Services\Kluis\VaultService;
use App\Support\FilamentNotifier;
use App\Support\Kluis\KluisOrderPlan;
use App\Support\Kluis\KluisThermometerReading;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

class VestixKluis extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Vestix Kluis';

    protected static ?string $title = 'Vestix Kluis';

    protected static ?string $slug = 'vestix-kluis';

    protected static string|\UnitEnum|null $navigationGroup = 'Strategisch';

    protected static ?int $navigationSort = 1;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?string $thermometerError = null;

    public function mount(VaultService $vault): void
    {
        /** @var User $user */
        $user = auth()->user();
        $settings = $vault->settingsFor($user);

        $this->form->fill([
            'budget' => (float) $settings->default_monthly_budget,
            'etf_ticker' => $settings->etf_ticker,
            'default_monthly_budget' => (float) $settings->default_monthly_budget,
            'overheat_threshold_pct' => (float) $settings->overheat_threshold_pct,
            'crash_threshold_pct' => (float) $settings->crash_threshold_pct,
            'overheat_invest_fraction' => (float) $settings->overheat_invest_fraction * 100,
            'dip_dry_powder_fraction' => (float) $settings->dip_dry_powder_fraction * 100,
            'crash_dry_powder_fraction' => (float) $settings->crash_dry_powder_fraction * 100,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maandelijkse inleg')
                    ->description('Vul het beschikbare maandbudget in. Vestix berekent het bevel op basis van de thermometer.')
                    ->schema([
                        TextInput::make('budget')
                            ->label('Beschikbaar maandbudget')
                            ->numeric()
                            ->minValue(0)
                            ->step(100)
                            ->suffix('€')
                            ->required()
                            ->live(onBlur: true),
                    ]),
                Section::make('Kluis-configuratie')
                    ->description('Eenmalig of zelden wijzigen. Alleen brede ETF-dekking — geen losse aandelen.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('etf_ticker')
                            ->label('Kern-ETF')
                            ->required()
                            ->maxLength(32)
                            ->helperText('Display-ticker (standaard VWCE). Provider-symbolen staan in config.'),
                        TextInput::make('default_monthly_budget')
                            ->label('Standaard maandbudget')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('€')
                            ->required(),
                        TextInput::make('overheat_threshold_pct')
                            ->label('Oververhit-drempel')
                            ->numeric()
                            ->suffix('%')
                            ->required(),
                        TextInput::make('crash_threshold_pct')
                            ->label('Crash-drempel')
                            ->numeric()
                            ->suffix('%')
                            ->required(),
                        TextInput::make('overheat_invest_fraction')
                            ->label('Oververhit: % van budget naar ETF')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
                        TextInput::make('dip_dry_powder_fraction')
                            ->label('Dip: % droog kruit inzetten')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
                        TextInput::make('crash_dry_powder_fraction')
                            ->label('Crash: % droog kruit inzetten')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
                        Actions::make([
                            Action::make('saveSettings')
                                ->label('Configuratie opslaan')
                                ->action('saveSettings'),
                        ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('vestix-kluis-form'),
                SchemaView::make('filament.pages.vestix-kluis-command')
                    ->viewData(fn (): array => $this->commandViewData()),
                Section::make('Logboek')
                    ->description('Bevestigde maandacties')
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshThermometer')
                ->label('Thermometer verversen')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->outlined()
                ->extraAttributes(['class' => 'vestix-sync-btn'])
                ->action('refreshThermometer'),
            Action::make('confirmMonth')
                ->label('Uitgevoerd bevestigen')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->extraAttributes(['class' => 'vestix-glow-btn'])
                ->requiresConfirmation()
                ->modalHeading('Maandbevel bevestigen?')
                ->modalDescription('Dit schrijft het bevel naar het logboek en werkt het droog kruit bij. Voer de broker-order zelf uit.')
                ->action('confirmMonth')
                ->disabled(fn (): bool => $this->reading() === null || $this->monthAlreadyConfirmed()),
        ];
    }

    public function saveSettings(VaultService $vault): void
    {
        /** @var User $user */
        $user = auth()->user();
        $state = $this->form->getState();

        $vault->updateSettings($vault->settingsFor($user), [
            'etf_ticker' => $state['etf_ticker'] ?? 'VWCE',
            'default_monthly_budget' => $state['default_monthly_budget'] ?? 10000,
            'overheat_threshold_pct' => $state['overheat_threshold_pct'] ?? 10,
            'crash_threshold_pct' => $state['crash_threshold_pct'] ?? 10,
            'overheat_invest_fraction' => ((float) ($state['overheat_invest_fraction'] ?? 50)) / 100,
            'dip_dry_powder_fraction' => ((float) ($state['dip_dry_powder_fraction'] ?? 25)) / 100,
            'crash_dry_powder_fraction' => ((float) ($state['crash_dry_powder_fraction'] ?? 50)) / 100,
        ]);

        unset($this->settings, $this->reading, $this->orderPlan);

        Notification::make()
            ->title('Kluis-configuratie opgeslagen')
            ->success()
            ->send();
    }

    public function refreshThermometer(VaultService $vault): void
    {
        /** @var User $user */
        $user = auth()->user();
        unset($this->settings, $this->reading, $this->orderPlan);
        $this->thermometerError = null;

        $reading = $vault->reading($vault->settingsFor($user), force: true);

        if ($reading === null) {
            $this->thermometerError = 'Kon SMA-200 / koers niet ophalen. Controleer de Polygon API-key en probeer opnieuw.';
            FilamentNotifier::send(
                title: 'Thermometer niet beschikbaar',
                body: $this->thermometerError,
                status: 'warning',
            );

            return;
        }

        FilamentNotifier::send(
            title: 'Thermometer ververst',
            body: $reading->message(),
            status: 'success',
        );
    }

    public function confirmMonth(VaultService $vault): void
    {
        /** @var User $user */
        $user = auth()->user();
        $budget = (float) ($this->form->getState()['budget'] ?? 0);
        $reading = $this->reading();

        if ($reading === null) {
            FilamentNotifier::send(
                title: 'Geen thermometerdata',
                body: 'Ververs eerst de thermometer voordat je bevestigt.',
                status: 'warning',
            );

            return;
        }

        try {
            $deposit = $vault->confirmMonth($user, $budget, $reading);
        } catch (ValidationException $exception) {
            FilamentNotifier::send(
                title: 'Bevestigen mislukt',
                body: collect($exception->errors())->flatten()->first() ?? 'Onbekende fout.',
                status: 'danger',
            );

            return;
        }

        unset($this->settings, $this->reading, $this->orderPlan);
        $this->resetTable();

        FilamentNotifier::send(
            title: 'Maandbevel bevestigd',
            body: sprintf(
                '€%s naar %s · droog kruit nu €%s',
                number_format((float) $deposit->etf_amount, 2, ',', '.'),
                $reading->ticker,
                number_format((float) $deposit->dry_powder_after, 2, ',', '.'),
            ),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                VaultDeposit::query()
                    ->where('user_id', auth()->id())
                    ->latest('period_month')
            )
            ->columns([
                TextColumn::make('period_month')
                    ->label('Maand')
                    ->date('M Y')
                    ->sortable(),
                TextColumn::make('climate')
                    ->label('Klimaat')
                    ->formatStateUsing(fn ($state): string => $state?->codeLabel() ?? '—')
                    ->badge()
                    ->color(fn ($state): string => $state?->color() ?? 'gray'),
                TextColumn::make('deviation_pct')
                    ->label('Afwijking')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : sprintf('%+.1f%%', (float) $state)),
                TextColumn::make('budget_input')
                    ->label('Budget')
                    ->money('EUR'),
                TextColumn::make('etf_amount')
                    ->label('Naar ETF')
                    ->money('EUR'),
                TextColumn::make('dry_powder_delta')
                    ->label('Droog kruit Δ')
                    ->formatStateUsing(fn ($state): string => sprintf(
                        '%s€%s',
                        (float) $state >= 0 ? '+' : '−',
                        number_format(abs((float) $state), 2, ',', '.'),
                    )),
                TextColumn::make('dry_powder_after')
                    ->label('Droog kruit na')
                    ->money('EUR'),
                TextColumn::make('confirmed_at')
                    ->label('Bevestigd')
                    ->dateTime('j M Y H:i'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Terugdraaien')
                    ->modalHeading('Maandbevestiging terugdraaien?')
                    ->modalDescription('Dit verwijdert de logregel en zet het droog kruit terug alsof deze maand niet bevestigd was. Alleen de laatste maand kan worden teruggedraaid.')
                    ->successNotificationTitle('Maandbevestiging teruggedraaid')
                    ->visible(function (VaultDeposit $record): bool {
                        $latestId = VaultDeposit::query()
                            ->where('user_id', auth()->id())
                            ->orderByDesc('period_month')
                            ->orderByDesc('id')
                            ->value('id');

                        return $latestId === $record->id;
                    })
                    ->using(function (VaultDeposit $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(VaultService::class)->revertDeposit($user, $record);
                        unset($this->settings, $this->reading, $this->orderPlan);
                    }),
            ])
            ->paginated([10, 25]);
    }

    /**
     * @return array<string, mixed>
     */
    public function commandViewData(): array
    {
        $settings = $this->settings();
        $reading = $this->reading();
        $plan = $this->orderPlan();

        return [
            'settings' => $settings,
            'reading' => $reading,
            'plan' => $plan,
            'error' => $this->thermometerError,
            'alreadyConfirmed' => $this->monthAlreadyConfirmed(),
            'dryPowder' => (float) $settings->dry_powder_balance,
        ];
    }

    #[Computed]
    public function settings(): VaultSetting
    {
        /** @var User $user */
        $user = auth()->user();

        return app(VaultService::class)->settingsFor($user);
    }

    #[Computed]
    public function reading(): ?KluisThermometerReading
    {
        try {
            return app(VaultService::class)->reading($this->settings());
        } catch (\Throwable $exception) {
            $this->thermometerError = $exception->getMessage();

            return null;
        }
    }

    #[Computed]
    public function orderPlan(): ?KluisOrderPlan
    {
        $reading = $this->reading();

        if ($reading === null) {
            return null;
        }

        $budget = (float) ($this->data['budget'] ?? $this->settings()->default_monthly_budget);

        return app(VaultService::class)->orderPlan($this->settings(), $budget, $reading);
    }

    public function monthAlreadyConfirmed(): bool
    {
        return VaultDeposit::query()
            ->where('user_id', auth()->id())
            ->whereDate('period_month', now()->startOfMonth()->toDateString())
            ->exists();
    }

    public function getSubheading(): string|HtmlString|null
    {
        return new HtmlString(
            'Strategische Smart DCA · één keer per maand · los van je swing-radar'
        );
    }
}
