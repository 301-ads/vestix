<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Enums\EarningsReleaseHour;
use App\Enums\PositionVisibility;
use App\Events\SquadRadarTargetPosted;
use App\Filament\Concerns\PollsPositionMarketData;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Positions\Tables\PositionRecordActions;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Models\Squad;
use App\Services\AssetSyncService;
use App\Services\MarketDataFetcher;
use App\Services\SquadContext;
use App\Support\EarningsExitDisplay;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFreshness;
use App\Support\ScoutSetupScorecard;
use App\Support\StopLossProtocol;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\HtmlString;

class EditPosition extends EditRecord
{
    use PollsPositionMarketData;

    protected static string $resource = PositionResource::class;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return [
            'fi-resource-edit-record-page',
            'vestix-position-edit',
        ];
    }

    public function mountCanAuthorizeAccess(): void
    {
        if (! $this->record instanceof Model) {
            return;
        }

        abort_unless(static::canAccess(['record' => $this->getRecord()]), 403);
    }

    public function mount(int|string $record): void
    {
        $position = Position::query()->findOrFail($record);

        if ($position->status === 'scout' && static::$resource === PositionResource::class) {
            throw new HttpResponseException(
                new RedirectResponse(ScoutResource::getUrl('edit', ['record' => $record])),
            );
        }

        if ($position->status !== 'scout' && static::$resource === ScoutResource::class) {
            throw new HttpResponseException(
                new RedirectResponse(PositionResource::getUrl('edit', ['record' => $record])),
            );
        }

        parent::mount($record);

        /** @var Position $position */
        $position = $this->getRecord();
        $position->loadMissing('asset');

        if ($position->asset && ! $position->asset->hasIcon()) {
            app(AssetSyncService::class)->ensureForTicker($position->ticker);
            $position->load('asset');
        }

        if (app(MarketDataFetcher::class)->backfillRecentClosePrices($position)) {
            $position->refresh();
        }

        if (MarketDataFreshness::isPositionSyncInProgress($position->id)) {
            $this->startPollingPositionMarketData();
        }
    }

    protected function getHeaderActions(): array
    {
        /** @var Position $record */
        $record = $this->getRecord();

        $actions = [
            PositionRecordActions::fetchMarketData(syncButtonStyle: true),
        ];

        if ($record->requiresEarningsExit()) {
            $actions[] = PositionRecordActions::holdThroughEarnings();
        }

        $overflowActions = [];

        if ($record->status === 'scout') {
            $actions[] = $this->scoutActivateAction();
            $overflowActions[] = PositionRecordActions::shareSetup();
        } else {
            $overflowActions[] = PositionRecordActions::shareSuccess();
            $overflowActions[] = PositionRecordActions::archive();
            $overflowActions[] = $this->applyCalculatedSlAction()
                ->extraAttributes(['class' => 'hidden']);
        }

        $overflowActions[] = DeleteAction::make();

        $actions[] = ActionGroup::make($overflowActions)->iconButton();

        return $actions;
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Position $record */
        $record = $this->getRecord();

        return new HtmlString(view('filament.positions.edit-page-heading', [
            'title' => $this->getRecordTitle(),
            'status' => $record->status,
            'pipelineStatus' => $record->status === 'scout' ? $record->scoutPipelineStatus() : null,
            'broker' => $record->status === 'scout' ? $record->effectiveBroker() : null,
            'iconUrl' => $record->asset?->icon_url,
        ])->render());
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Position $record */
        $record = $this->getRecord();

        if ($record->status !== 'closed' || $record->exit_price === null || $record->closed_at === null) {
            return null;
        }

        return sprintf(
            'Exit: $%s — gesloten op %s',
            number_format((float) $record->exit_price, 2),
            $record->closed_at->translatedFormat('j M Y'),
        );
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Position $record */
        $record = $this->getRecord();
        $record->loadMissing('asset');

        if ($record->asset) {
            $data['asset_earnings_date_override'] = $record->asset->earnings_date_override;
            $data['asset_earnings_hour_override'] = $record->asset->earnings_hour_override?->value;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['status'], $data['exit_price'], $data['closed_at']);
        unset($data['asset_earnings_date_override'], $data['asset_earnings_hour_override']);

        if (($this->getRecord()->status ?? null) === 'scout') {
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
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Position $record */
        $record = $this->getRecord();
        $record->loadMissing('asset');

        $state = $this->form->getRawState();
        $asset = $record->asset;

        if ($asset && array_key_exists('asset_earnings_date_override', $state)) {
            $hour = filled($state['asset_earnings_hour_override'] ?? null)
                ? EarningsReleaseHour::tryFrom((string) $state['asset_earnings_hour_override'])
                : null;

            if ($hour === EarningsReleaseHour::Unknown) {
                $hour = null;
            }

            $asset->update([
                'earnings_date_override' => $state['asset_earnings_date_override'] ?: null,
                'earnings_hour_override' => $hour,
            ]);
        }

        if ($record->status !== 'scout' || $record->visibility !== PositionVisibility::Squad) {
            return;
        }

        if ($record->wasChanged('visibility') || $record->wasRecentlyCreated) {
            SquadRadarTargetPosted::dispatch($record);
        }
    }

    /**
     * @return array{
     *     totalPoints: int,
     *     maxPoints: int,
     *     grade: string,
     *     gradeLabel: string,
     *     hardFailReasons: array<int, string>,
     *     criteria: array<int, array<string, mixed>>,
     * }
     */
    protected function resolveSetupScoreFromForm(): array
    {
        /** @var Position $record */
        $record = $this->getRecord();

        return $record->evaluateSetupScore([
            'signal_low' => $this->data['signal_low'] ?? $record->signal_low,
            'latest_open_price' => $this->data['latest_open_price'] ?? $record->latest_open_price,
            'latest_close_price' => $this->data['latest_close_price'] ?? $record->latest_close_price,
            'latest_sma_20' => $this->data['latest_sma_20'] ?? $record->latest_sma_20,
            'sma_20_five_days_ago' => $this->data['sma_20_five_days_ago'] ?? $record->sma_20_five_days_ago,
            'sma_20_ten_days_ago' => $this->data['sma_20_ten_days_ago'] ?? $record->sma_20_ten_days_ago,
            'latest_sma_50' => $this->data['latest_sma_50'] ?? $record->latest_sma_50,
            'scout_rsi' => $this->data['scout_rsi'] ?? $record->scout_rsi,
            'bounce_volume_above_average' => (bool) ($this->data['bounce_volume_above_average'] ?? $record->bounce_volume_above_average),
            'relative_volume' => $this->data['relative_volume'] ?? $record->relative_volume,
            'bounce_day_volume' => $this->data['bounce_day_volume'] ?? $record->bounce_day_volume,
            'volume_sma_20' => $this->data['volume_sma_20'] ?? $record->volume_sma_20,
            'sector_etf' => $this->data['sector_etf'] ?? $record->sector_etf,
            'sector_trend_positive' => (bool) ($this->data['sector_trend_positive'] ?? $record->sector_trend_positive),
            'pre_bounce_extension_atr' => $this->data['pre_bounce_extension_atr'] ?? $record->pre_bounce_extension_atr,
        ]);
    }

    protected function applyCalculatedSlAction(): Action
    {
        return Action::make('applyCalculatedSl')
            ->label('SL bijwerken')
            ->tooltip('Stop-Loss bijwerken naar berekende SL')
            ->icon('heroicon-o-check')
            ->color('warning')
            ->visible(function (): bool {
                /** @var Position $record */
                $record = $this->getRecord();

                if ($record->status !== 'open') {
                    return false;
                }

                $position = StopLossProtocol::applyOverrides($record, [
                    'latest_sma_20' => $this->data['latest_sma_20'] ?? $record->latest_sma_20,
                    'latest_atr_14' => $this->data['latest_atr_14'] ?? $record->latest_atr_14,
                    'latest_close_price' => $this->data['latest_close_price'] ?? $record->latest_close_price,
                    'scout_rsi' => $this->data['scout_rsi'] ?? $record->scout_rsi,
                    'prior_day_low' => $this->data['prior_day_low'] ?? $record->prior_day_low,
                ]);
                $newSl = StopLossProtocol::resolve($position);
                $currentSl = $this->data['current_sl'] ?? $record->current_sl;

                return $newSl !== null
                    && $currentSl !== null
                    && $newSl > (float) $currentSl;
            })
            ->requiresConfirmation()
            ->modalHeading('Stop-Loss bijwerken')
            ->modalDescription('Huidige SL vervangen door de berekende nieuwe SL?')
            ->action(function (): void {
                /** @var Position $record */
                $record = $this->getRecord();

                $position = StopLossProtocol::applyOverrides($record, [
                    'latest_sma_20' => $this->data['latest_sma_20'] ?? $record->latest_sma_20,
                    'latest_atr_14' => $this->data['latest_atr_14'] ?? $record->latest_atr_14,
                    'latest_close_price' => $this->data['latest_close_price'] ?? $record->latest_close_price,
                    'scout_rsi' => $this->data['scout_rsi'] ?? $record->scout_rsi,
                    'prior_day_low' => $this->data['prior_day_low'] ?? $record->prior_day_low,
                ]);
                $newSl = StopLossProtocol::resolve($position);

                if ($newSl === null) {
                    return;
                }

                $record->update(['current_sl' => $newSl]);
                $this->data['current_sl'] = $newSl;

                FilamentNotifier::send(title: 'Stop-Loss geüpdatet!');
            });
    }

    protected function scoutActivateAction(): Action
    {
        return PositionRecordActions::activateScout(iconButton: false)
            ->color('primary')
            ->extraAttributes(fn (): array => $this->scoutActivateExtraAttributes())
            ->disabled(fn (): bool => $this->scoutActivationDisabled())
            ->tooltip(fn (): string => $this->scoutActivateTooltip());
    }

    protected function scoutActivationDisabled(): bool
    {
        /** @var Position $record */
        $record = $this->getRecord();

        return PositionRecordActions::scoutActivationDisabled($record);
    }

    /**
     * @return array<string, string>
     */
    protected function scoutActivateExtraAttributes(): array
    {
        $classes = ['vestix-btn-primary'];

        $score = $this->resolveSetupScoreFromForm();

        if ($score['hardFailReasons'] === [] && $score['grade'] === 'A++') {
            $classes[] = 'scout-activate-a-plus';
        }

        return ['class' => implode(' ', $classes)];
    }

    protected function scoutActivateTooltip(): string
    {
        /** @var Position $record */
        $record = $this->getRecord();

        if (EarningsExitDisplay::isWithinAlertWindow($record)) {
            return PositionRecordActions::scoutActivationTooltip($record);
        }

        if (PositionRecordActions::scoutActivationDisabled($record)) {
            return PositionRecordActions::scoutActivationTooltip($record);
        }

        $score = $this->resolveSetupScoreFromForm();

        if ($score['hardFailReasons'] !== []) {
            return implode(' ', $score['hardFailReasons']);
        }

        return match (true) {
            $score['grade'] === 'A++' => 'A++ SETUP — visueel bevestigde perfecte trade',
            $score['grade'] === 'A' => 'A SETUP — handmatig bevestigde sterke trade',
            $score['totalPoints'] === ScoutSetupScorecard::maxPoints() => 'Perfecte score — promoveer naar A++ na visuele check',
            $score['totalPoints'] >= 8 => 'Sterke score — promoveer naar A na visuele check',
            $score['totalPoints'] === 7 => 'B SETUP — standaard trade, wees alert op risico',
            $score['totalPoints'] >= 5 => 'C SETUP — wiskundig zwak, alleen monitoren',
            default => 'NO TRADE — objectieve afwijzing',
        };
    }
}
